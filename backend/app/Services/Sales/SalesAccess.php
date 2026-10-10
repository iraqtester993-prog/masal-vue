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
        $this->authority->require($actor, 'account.view');
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

    public function own(User $actor, string $permission, bool $lock = false): Account
    {
        $this->require($actor, $permission);
        $own = $this->accounts($actor)->findOrFail($actor->membership->account_id);
        abort_unless(in_array($own->type, [AccountType::SubAgent, AccountType::SubBranch, AccountType::Pos], true), 403, 'البيع والطباعة من حساب الوكيل الفرعي أو نقطة البيع فقط.');
        if ($lock) {
            $ids = DB::table('account_closure')->where('descendant_id', $own->id)->pluck('ancestor_id');
            Account::whereIn('id', $ids)->where('type', '!=', AccountType::System)->orderBy('id')->lockForUpdate()->get();
            $own->refresh();
        }
        abort_unless($own->isOperational(), 409, 'حسابك أو أحد الحسابات الأعلى موقوف.');

        return $own;
    }

    public function main(Account $own): Account
    {
        return Account::whereIn('id', DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id'))->where('type', AccountType::MainAgent)->firstOrFail();
    }

    public function sale(User $actor, int $id, string $permission, bool $owned = false, bool $lock = false): Sale
    {
        $this->require($actor, $permission);
        if ($owned) {
            $own = $this->own($actor, $permission, $lock);
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
        abort_unless($product->provider->connection === 'ملفات', 422, 'هذه الخدمة تحتاج مسار تنفيذ المزود المرتبط.');
        abort_unless($account->city && OperatingGovernorate::where('name', $account->city)->where('active', true)->exists(), 409, 'محافظة حساب البيع غير مفعلة.');
        abort_unless(! $product->allowed_cities || in_array($account->city, $product->allowed_cities, true), 403, 'الفئة غير متاحة في محافظة حسابك.');

        return $product;
    }

    public function device(User $actor, Account $own, Request $request, array $settings): ?DeviceSession
    {
        if ($own->type !== AccountType::Pos) {
            return null;
        }
        $device = DeviceSession::where('account_id', $own->id)->where('user_id', $actor->id)->where('session_hash', hash('sha256', $request->session()->getId()))->where('session_version', $actor->session_version)->where('expires_at', '>', now())->first();
        abort_unless($device, 409, 'جلسة جهاز نقطة البيع غير متصلة؛ افتح جلسة الجهاز وتابع الاتصال.');
        abort_unless(version_compare($device->app_version, $settings['min_app_version'], '>='), 409, 'إصدار التطبيق أقل من الحد المطلوب.');
        abort_if(! empty($settings['min_os_version']) && (! $device->os_version || version_compare($device->os_version, $settings['min_os_version'], '<')), 409, 'إصدار نظام الجهاز أقل من الحد المطلوب.');
        abort_if($own->device_lock_enabled && $own->serial && $device->serial !== $own->serial, 403, 'الجهاز لا يطابق الرقم التسلسلي المعتمد.');

        return $device;
    }
}
