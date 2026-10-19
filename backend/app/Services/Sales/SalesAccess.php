<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\OperatingGovernorate;
use App\Models\Sales\DeviceSession;
use App\Models\Sales\Sale;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\CatalogAccess;
use App\Services\Digital\DigitalAccess;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority, private CatalogAccess $catalog) {}

    public function require(User $actor, string $permission, bool $system = false): void
    {
        $this->authority->require($actor, $permission);
        if (str_starts_with($permission, 'sell.') || str_starts_with($permission, 'sales.')) {
            $this->authority->require($actor, 'sales.view');
        }
        if (str_starts_with($permission, 'exceptions.') && $permission !== 'exceptions.view') {
            $this->authority->require($actor, 'exceptions.view');
        }
        foreach (['branding', 'security'] as $module) {
            if (str_starts_with($permission, $module.'.') && $permission !== $module.'.view') {
                $this->authority->require($actor, $module.'.view');
            }
        }
        if ($system) {
            abort_unless($actor->membership->account->type === AccountType::System && $this->accounts($actor)->whereKey($actor->membership->account_id)->exists(), 403);
        }
    }

    public function accounts(User $actor): Builder
    {
        return $this->scope->query($actor);
    }

    public function sales(User $actor): Builder
    {
        return Sale::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
    }

    public function own(User $actor, string $permission, bool $lock = false, bool $shareAncestors = false): Account
    {
        $this->require($actor, $permission);
        $own = $this->accounts($actor)->findOrFail($actor->membership->account_id);
        abort_unless(in_array($own->type, [AccountType::SubAgent, AccountType::SubBranch, AccountType::Pos], true), 403, 'البيع والطباعة من حساب الوكيل الفرعي أو نقطة البيع فقط.');
        if ($lock) {
            $ids = DB::table('account_closure')->where('descendant_id', $own->id)->pluck('ancestor_id');
            app(MutationGuard::class)->lockAccounts($ids->all(), $actor->membership->account_id, shareAncestors: $shareAncestors);
            $own->refresh();
        }
        abort_unless($own->isOperational(), 409, 'حسابك أو أحد الحسابات الأعلى موقوف.');

        return $own;
    }

    public function main(Account $own): Account
    {
        return RequestReadCache::remember('SalesAccess.main:'.$own->id, fn (): Account => Account::whereIn('id', DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id'))->where('type', AccountType::MainAgent)->firstOrFail());
    }

    public function sale(User $actor, int $id, string $permission, bool $owned = false, bool $lock = false, bool $shareAncestors = false): Sale
    {
        $this->require($actor, $permission);
        if ($owned) {
            $own = $this->own($actor, $permission, $lock, $shareAncestors);
        }
        $query = $this->sales($actor);
        $sale = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        if ($owned) {
            abort_unless($sale->account_id === $own->id, 403, 'البطاقات والوصل لصاحب البيع فقط.');
        }

        return $sale;
    }

    public function product(User $actor, Account $account, int $productId): CatalogProduct
    {
        abort_unless($this->catalog->products($actor)->whereKey($productId)->exists(), 404);
        $product = $this->catalog->forAccount($account->id)->with('provider')->findOrFail($productId);
        abort_if(Schema::hasTable('digital_offers') && app(DigitalAccess::class)->isAssignedProduct($account, $productId), 422, 'هذه الفئة مرتبطة بخدمة رقمية؛ نفّذها من مسار خدمة المزود.');
        abort_unless($product->status === 'active' && $product->provider->status === 'active', 409, 'الفئة أو المزود غير مفعل.');
        abort_unless($this->printableProducts(CatalogProduct::query()->whereKey($productId), $this->main($account))->exists(), 422, 'هذه الخدمة تحتاج مسار تنفيذ المزود المرتبط أو مخزون بطاقات صالح للطباعة.');
        abort_unless($account->city && OperatingGovernorate::where('name', $account->city)->where('active', true)->exists(), 409, 'محافظة حساب البيع غير مفعلة.');
        abort_unless(! $product->allowed_cities || in_array($account->city, $product->allowed_cities, true), 403, 'الفئة غير متاحة في محافظة حسابك.');

        return $product;
    }

    public function printableProducts(Builder $query, Account $main): Builder
    {
        return $query->whereHas('provider', fn (Builder $provider) => $provider->where('status', 'active'))
            ->where(function (Builder $products) use ($main): void {
                $products->whereHas('provider', fn (Builder $provider) => $provider->where('connection', 'ملفات'))
                    ->orWhere(function (Builder $apiProducts) use ($main): void {
                        $apiProducts->whereHas('provider', fn (Builder $provider) => $provider->where('connection', 'API'))
                            ->whereExists(fn ($stock) => $stock->selectRaw('1')->from('stock_cards')
                                ->join('stock_batches', 'stock_batches.id', '=', 'stock_cards.batch_id')
                                ->whereColumn('stock_cards.product_id', 'catalog_products.id')
                                ->whereColumn('stock_batches.currency', 'catalog_products.currency')
                                ->where('stock_cards.account_id', $main->id)
                                ->where('stock_batches.account_id', $main->id)
                                ->where('stock_cards.status', 'Available')->where('stock_cards.credit_held', false)
                                ->whereNull('stock_cards.sale_id')->where('stock_cards.expiry', '>', now('Asia/Baghdad')->toDateString())
                                ->whereIn('stock_batches.status', ['Loaded', 'Partially Used']));
                    });
            });
    }

    public function device(User $actor, Account $own, Request $request, array $settings): ?DeviceSession
    {
        if ($own->type !== AccountType::Pos) {
            return null;
        }
        app(PosLoginDevice::class)->assertCurrent($actor, $request);
        $deviceQuery = DeviceSession::where('account_id', $own->id)->where('user_id', $actor->id)->where('session_hash', hash('sha256', $request->session()->getId()))->where('session_version', $actor->session_version)->where('expires_at', '>', now());
        $device = (DB::transactionLevel() > 0 ? $deviceQuery->sharedLock() : $deviceQuery)->first();
        abort_unless($device, 409, 'جلسة جهاز نقطة البيع غير متصلة؛ افتح جلسة الجهاز وتابع الاتصال.');
        abort_unless(version_compare($device->app_version, $settings['min_app_version'], '>='), 409, 'إصدار التطبيق أقل من الحد المطلوب.');
        abort_if(! empty($settings['min_os_version']) && (! $device->os_version || version_compare($device->os_version, $settings['min_os_version'], '<')), 409, 'إصدار نظام الجهاز أقل من الحد المطلوب.');
        if ($own->device_lock_enabled && ($device->registered_device_id || $own->device_binding_enabled)) {
            $registeredQuery = DB::table('pos_registered_devices')->where('account_id', $own->id)->where('id', $device->registered_device_id)->where('status', 'approved');
            $registered = (DB::transactionLevel() > 0 ? $registeredQuery->sharedLock() : $registeredQuery)->first();
            abort_unless($registered && app(RegisteredDevices::class)->sessionProofValid($registered, $device), 403, 'هذا الجهاز بانتظار اعتماد الوكيل أو مدير النظام، أو تم إلغاء اعتماده.');
        } else {
            abort_if($own->device_lock_enabled && $own->serial && $device->serial !== $own->serial, 403, 'الجهاز لا يطابق الرقم التسلسلي المعتمد.');
        }

        return $device;
    }
}
