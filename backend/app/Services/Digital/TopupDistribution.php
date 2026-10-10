<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TopupDistribution
{
    public function __construct(private DigitalAccess $access, private FinanceOperations $operations, private AuditLogger $audit) {}

    private function distributor(User $actor): Account
    {
        if ($actor->membership->account->type === AccountType::System) {
            $this->access->owner($actor);

            return $this->access->own($actor, 'integrations.edit');
        }
        $own = $this->access->own($actor, 'digital.assign');
        abort_unless(in_array($own->type, [AccountType::MainAgent, AccountType::SubAgent, AccountType::SubBranch], true), 403);

        return $own;
    }

    private function targets(User $actor, Account $own): Builder
    {
        return $this->access->accounts($actor)->where('parent_id', $own->id)
            ->whereIn('type', $own->type === AccountType::System ? [AccountType::MainAgent] : [AccountType::SubAgent, AccountType::SubBranch, AccountType::Pos]);
    }

    public function main(Account $account): ?Account
    {
        $ancestors = DB::table('account_closure')->where('descendant_id', $account->id)->select('ancestor_id');

        return Account::whereIn('id', $ancestors)->where('type', AccountType::MainAgent)->first();
    }

    public function effective(Account $target): Collection
    {
        $main = $this->main($target);
        if (! $main || ! $target->isOperational()) {
            return collect();
        }
        $grants = TopupGrant::where('main_account_id', $main->id)->whereIn('target_account_id', DB::table('account_closure')->where('descendant_id', $target->id)->select('ancestor_id'))->get()->keyBy('target_account_id');
        $ids = null;
        $current = $target;
        $seen = [];
        while (true) {
            if (isset($seen[$current->id]) || ! $current->isOperational()) {
                return collect();
            }
            $seen[$current->id] = true;
            $grant = $grants->get($current->id);
            if (! $grant || ! $grant->active || $grant->from_account_id !== $current->parent_id) {
                return collect();
            }
            $ids = $ids === null ? $grant->category_ids : array_values(array_intersect($ids, $grant->category_ids));
            if (! $ids) {
                return collect();
            }
            if ($current->id === $main->id) {
                break;
            }
            $current = Account::find($current->parent_id);
            if (! $current || $current->type === AccountType::System) {
                return collect();
            }
        }

        return $this->categories($main)->whereIn('id', $ids)->orderBy('id')->get();
    }

    private function categories(?Account $main = null, ?int $connectionId = null): Builder
    {
        $query = TopupCategory::where('active', true)->where('cost_minor', '>', 0);
        $connection = $connectionId ? DigitalConnection::where('provider', 'topup')->find($connectionId) : app(TopupAccounting::class)->connection($main);
        $snapshots = $connection ? CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential))->pluck('id')->all() : [];

        return $query->where('connection_id', $connection?->id ?? 0)->whereIn('catalog_snapshot_id', $snapshots);
    }

    public function categoryDto(TopupCategory $category): array
    {
        return ['id' => $category->id, 'name' => $category->name, 'type' => $category->type, 'price' => Money::decimal($category->retail_minor), 'admin_price' => Money::decimal($category->retail_minor), 'version' => $category->version, 'company' => 'آسياسيل', 'remote_id' => $category->remote_id];
    }

    private function grantDto(?TopupGrant $grant): array
    {
        return ['category_ids' => $grant?->category_ids ?? [], 'active' => $grant?->active ?? true, 'version' => $grant?->version ?? 0, 'connection_id' => $grant?->connection_id, 'balance' => Money::decimal($grant?->balance_minor ?? 0), 'held' => Money::decimal($grant?->held_minor ?? 0), 'available' => Money::decimal(($grant?->balance_minor ?? 0) - ($grant?->held_minor ?? 0)), 'spent' => Money::decimal($grant?->spent_minor ?? 0)];
    }

    private function connectionDto(?Account $main, bool $balance = false, bool $details = false, ?int $connectionId = null): ?array
    {
        if (! $main) {
            return null;
        }
        $connection = $connectionId ? DigitalConnection::find($connectionId) : app(TopupAccounting::class)->connection($main);
        $catalog = $details && $connection ? CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential))->latest('id')->first() : null;

        return ['id' => $connection?->id, 'version' => $connection?->version, 'main_account_id' => $main->id, 'main_account_name' => $main->name, 'token_present' => (bool) $connection?->credential, 'active' => (bool) $connection?->active] + ($balance ? ['company_balance' => $connection?->company_balance_minor === null ? null : Money::decimal($connection->company_balance_minor), 'balance_updated_at' => $connection?->balance_updated_at?->toISOString()] : []) + ($details ? ['excluded_catalog' => $catalog?->excluded_catalog, 'catalog_updated_at' => $catalog?->created_at?->toISOString()] : []);
    }

    public function options(User $actor, ?int $targetId, ?int $connectionId = null): array
    {
        $own = $this->distributor($actor);
        $targets = $this->targets($actor, $own)->orderBy('id')->get();
        $target = $targetId ? $targets->firstWhere('id', $targetId) : $targets->first();
        abort_if($targetId && ! $target, 404);
        $grant = $target ? TopupGrant::where('target_account_id', $target->id)->first() : null;
        $connections = $own->type === AccountType::System ? $this->access->connections($actor)->where('provider', 'topup')->get() : collect();
        if ($connectionId) {
            abort_unless($own->type === AccountType::System && $connections->contains('id', $connectionId), 404);
        }
        $connectionId ??= $grant?->connection_id ?? app(TopupAccounting::class)->connection($target)?->id ?? $connections->first()?->id;
        $available = $own->type === AccountType::System ? $this->categories($target, $connectionId)->orderBy('id')->get() : $this->effective($own);
        $mainGrant = app(TopupAccounting::class)->grant($own->type === AccountType::System ? ($target ?? $own) : $own);

        return [
            'targets' => $targets->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type->value, 'operational' => $a->isOperational()])->all(),
            'target_id' => $target?->id,
            'categories' => $available->map(fn ($row): array => array_replace($this->categoryDto($row), ['price' => Money::decimal(max($row->retail_minor, (int) ($mainGrant?->retail_prices[(string) $row->id] ?? $row->retail_minor)))]))->all(),
            'connections' => $connections->map(fn ($row): array => ['id' => $row->id, 'name' => 'Topup #'.$row->id, 'balance' => $row->company_balance_minor === null ? null : Money::decimal($row->company_balance_minor), 'available' => app(TopupAccounting::class)->free($row) === null ? null : Money::decimal(app(TopupAccounting::class)->free($row))])->all(),
            'connection_id' => $connectionId,
            'allocation' => $this->grantDto($mainGrant),
            'can_edit_prices' => $own->type === AccountType::MainAgent,
            'grant' => $this->grantDto($grant),
            'effective_category_ids' => $target ? $this->effective($target)->pluck('id')->all() : [],
            'connection' => $this->connectionDto($target ? $this->main($target) : $this->main($own), $own->type === AccountType::System, $own->type === AccountType::System || $own->type === AccountType::MainAgent, $own->type === AccountType::System ? $connectionId : null),
            'can_manage_categories' => $own->type === AccountType::System,
            'execution_ready' => $available->isNotEmpty(),
        ];
    }

    public function save(User $actor, int $targetId, array $data, Request $request): array
    {
        $own = $this->distributor($actor);
        $this->targets($actor, $own)->findOrFail($targetId);
        $action = $own->type === AccountType::System ? 'integrations.edit' : 'digital.assign';

        return $this->operations->execute($actor, $action, $data + ['account_id' => $targetId, 'module' => 'topup.distribution'], function () use ($actor, $targetId, $data, $request): array {
            $own = $this->distributor($actor);
            $target = $this->targets($actor, $own)->lockForUpdate()->findOrFail($targetId);
            abort_unless($target->isOperational(), 409, 'الحساب أو أحد الحسابات الأعلى موقوف.');
            $main = $this->main($target);
            abort_unless($main, 409, 'الحساب غير تابع لوكيل رئيسي.');
            $grant = TopupGrant::where('target_account_id', $targetId)->lockForUpdate()->first();
            abort_unless(($grant?->version ?? 0) === $data['version'], 409, 'تغير تخصيص الفئات؛ حدّث الصفحة.');
            $allocation = [];
            $connectionId = $grant?->connection_id;
            if ($own->type === AccountType::System && isset($data['connection_id'])) {
                $connection = $this->access->connections($actor)->where('provider', 'topup')->lockForUpdate()->findOrFail($data['connection_id']);
                abort_unless($connection->active && $connection->company_balance_minor !== null, 422, 'استعلم عن رصيد الربط المفعّل قبل تخصيصه.');
                $connectionId = $connection->id;
                $balance = array_key_exists('allocation_balance', $data) ? DigitalMoney::minor($data['allocation_balance'], 'allocation_balance', true) : ($grant?->balance_minor ?? 0);
                $held = $grant?->held_minor ?? 0;
                abort_if($balance < $held, 422, 'لا يمكن سحب المبلغ المحجوز لعمليات معلقة.');
                if ($grant?->connection_id && $grant->connection_id !== $connectionId) {
                    abort_unless($grant->balance_minor === 0 && $held === 0, 409, 'استرجع الرصيد المتاح قبل تغيير توكن حصة الوكيل.');
                }
                $others = (int) TopupGrant::where('connection_id', $connectionId)->where('target_account_id', '!=', $targetId)->sum('balance_minor');
                abort_if($balance + $others > $connection->company_balance_minor, 422, 'التخصيص يتجاوز رصيد التبب المتبقي لدى الإدارة.');
                $allocation = ['connection_id' => $connectionId, 'balance_minor' => $balance];
            }
            abort_if($own->type !== AccountType::System && (isset($data['connection_id']) || array_key_exists('allocation_balance', $data)), 403);
            $available = $own->type === AccountType::System ? $this->categories($target, $connectionId)->lockForUpdate()->pluck('id')->all() : $this->effective($own)->pluck('id')->all();
            abort_if(array_diff($data['category_ids'], $available), 422, 'لا يمكنك منح فئة موقوفة أو غير ممنوحة لك.');
            $ids = $data['category_ids'];
            sort($ids, SORT_NUMERIC);
            $grant = TopupGrant::updateOrCreate(['target_account_id' => $targetId], $allocation + ['main_account_id' => $main->id, 'from_account_id' => $own->id, 'category_ids' => $ids, 'active' => $data['active'], 'version' => ($grant?->version ?? 0) + 1]);
            $this->audit->record('topup.categories.assigned', $request, $actor, $targetId, ['main_account_id' => $main->id, 'category_ids' => $ids, 'active' => $grant->active, 'version' => $grant->version, 'connection_id' => $connectionId, 'balance' => Money::decimal($grant->balance_minor)]);

            return $this->grantDto($grant);
        });
    }

    public function available(User $actor): array
    {
        $own = $this->access->own($actor, 'digital.view');
        abort_unless($own->type === AccountType::Pos, 403);
        $main = $this->main($own);

        $connection = app(TopupAccounting::class)->connection($main)?->load(['offers.product.provider', 'grants.target', 'account', 'company']);
        $offers = $connection ? $this->access->offers($connection, $own)->whereNotNull('topup_category_id')->keyBy('topup_category_id') : collect();
        $configuration = app(DigitalConfiguration::class);
        $gateway = $connection ? app(ProviderGateway::class)->status($connection) : null;
        $categories = $this->effective($own)->map(function ($row) use ($actor, $offers, $connection, $configuration, $gateway): array {
            $offer = $offers->get($row->id);

            $data = $this->categoryDto($row);
            $data['price'] = $offer ? $configuration->offerDto($offer, $actor)['retail'] : Money::decimal($row->retail_minor);

            return $data + ['offer' => $offer ? $configuration->offerDto($offer, $actor) + ['connection_id' => $connection->id, 'provider' => 'topup', 'gateway' => $gateway] : null];
        });

        return ['categories' => $categories->all(), 'connection' => $this->connectionDto($main), 'execution_ready' => $offers->isNotEmpty() && $gateway['configured'] && $gateway['purchases_enabled']];
    }

    public function prices(User $actor, array $data, Request $request): array
    {
        $own = $this->distributor($actor);
        abort_unless($own->type === AccountType::MainAgent, 403);

        return $this->operations->execute($actor, 'digital.assign', $data + ['account_id' => $own->id, 'module' => 'topup.prices'], function () use ($actor, $own, $data, $request): array {
            $connection = app(TopupAccounting::class)->connection($own);
            abort_unless($connection, 409, 'لم تخصص حصة تبب للوكيل.');
            DigitalConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $grant = app(TopupAccounting::class)->grant($own, true);
            abort_unless($grant && $grant->version === $data['version'], 409, 'تغير تخصيص التبب؛ حدّث الصفحة.');
            $allowed = $this->effective($own)->keyBy('id');
            $prices = $grant->retail_prices ?? [];
            foreach ($data['prices'] as $row) {
                $category = $allowed->get($row['category_id']);
                $price = DigitalMoney::minor($row['price'], 'price');
                abort_unless($category && $price >= $category->retail_minor, 422, 'سعر الوكيل لا يمكن أن يقل عن سعر مدير النظام.');
                $prices[(string) $category->id] = $price;
            }
            $grant->update(['retail_prices' => $prices, 'version' => $grant->version + 1]);
            $this->audit->record('topup.prices.updated', $request, $actor, $own->id, ['prices' => $data['prices'], 'connection_id' => $connection->id]);

            return $this->grantDto($grant);
        });
    }
}
