<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\CatalogAccess;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DigitalAccess
{
    public function __construct(private AccountScope $scope, public CatalogAccess $catalog, public ManagementAuthority $authority) {}

    public function require(User $actor, string $permission): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
        $this->authority->require($actor, str_starts_with($permission, 'integrations.') ? 'integrations.view' : 'digital.view');
    }

    public function accounts(User $actor): Builder
    {
        return $this->scope->query($actor);
    }

    public function owner(User $actor): void
    {
        $this->require($actor, 'integrations.edit');
        abort_unless($actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System && $this->accounts($actor)->whereKey($actor->membership->account_id)->exists(), 403, 'إعداد الربط لإدارة النظام المالكة فقط.');
    }

    public function own(User $actor, string $permission, bool $lock = false): Account
    {
        $this->require($actor, $permission);
        $own = $this->accounts($actor)->findOrFail($actor->membership->account_id);
        abort_unless($own->isOperational(), 409, 'الحساب أو أحد الحسابات الأعلى موقوف.');
        if ($lock) {
            $ids = DB::table('account_closure')->where('descendant_id', $own->id)->pluck('ancestor_id');
            app(MutationGuard::class)->lockAccounts($ids->all(), $actor->membership->account_id, shareAncestors: true);
            $own->refresh();
        }

        return $own;
    }

    public function connections(User $actor): Builder
    {
        $own = $actor->membership->account;
        if ($own->type === AccountType::System) {
            return DigitalConnection::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
        }
        if (! $this->accounts($actor)->whereKey($own->id)->exists()) {
            return DigitalConnection::whereRaw('1 = 0');
        }
        $ancestors = DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id');

        return DigitalConnection::whereIn('account_id', $ancestors);
    }

    public function connection(User $actor, int $id, string $permission = 'digital.view', bool $lock = false): DigitalConnection
    {
        $this->require($actor, $permission);
        $query = $this->connections($actor);
        $connection = ($lock ? $query->lockForUpdate() : $query)->with(['account', 'company', 'offers.product.provider', 'grants.target'])->findOrFail($id);
        if ($actor->membership->account->type !== AccountType::System) {
            $this->accounts($actor)->findOrFail($actor->membership->account_id);
            abort_unless($connection->account_id === $actor->membership->account_id || $this->offers($connection, $actor->membership->account, true)->isNotEmpty(), 404);
        }

        return $connection;
    }

    /** @return array{DigitalConnection, ?DigitalOffer} */
    public function saleOffer(User $actor, Account $own, int $connectionId, int $offerId): array
    {
        $this->require($actor, 'digital.create');
        abort_unless(DB::transactionLevel() > 0 && $own->id === $actor->membership->account_id && $own->type === AccountType::Pos, 403);
        $ancestors = DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id');
        $connection = $this->connections($actor)->lockForUpdate()->with([
            'account', 'company',
            'offers' => fn ($query) => $query->whereKey($offerId)->with('product.provider'),
            'grants' => fn ($query) => $query->whereIn('target_account_id', $ancestors),
        ])->findOrFail($connectionId);

        $offer = $this->offers($connection, $own)->firstWhere('id', $offerId);
        if (! $offer && $connection->account_id !== $own->id) {
            $connection->load('offers.product.provider');
            abort_if($this->offers($connection, $own, true)->isEmpty(), 404);
        }

        return [$connection, $offer];
    }

    public function offers(DigitalConnection $connection, Account $target, bool $includePaused = false): Collection
    {
        $connection->loadMissing('offers.product.provider');
        if (! $target->isOperational() || ! $connection->account->isOperational() || $connection->provider_id !== null && $connection->company?->status !== 'active' || ! $connection->active && ! $includePaused) {
            return collect();
        }
        $snapshots = CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', DigitalConfiguration::credentialHash($connection->credential))->pluck('id')->all();
        $effective = $connection->provider === 'topup' ? app(TopupDistribution::class)->effective($target)->keyBy('id') : collect();
        $offers = $connection->offers->filter(function ($offer) use ($snapshots, $effective, $connection): bool {
            $category = $effective->get($offer->topup_category_id);
            $verified = $offer->manual_category_version === null && in_array($offer->catalog_snapshot_id, $snapshots, true);
            if ($connection->provider === 'topup' && $offer->topup_category_id !== null) {
                $verified = $verified && $category && $category->connection_id === $connection->id && $category->catalog_snapshot_id === $offer->catalog_snapshot_id && $category->remote_id === $offer->remote_id && $category->type === $offer->type;
            }

            return $offer->listed && $offer->active && $offer->remote_id !== null && $offer->cost_minor > 0 && $offer->product->status === 'active' && $offer->product->provider?->status === 'active' && $verified;
        });
        $synced = collect();
        if ($connection->provider === 'topup') {
            $categories = $effective->keys()->all();
            $synced = $offers->filter(fn ($offer): bool => $offer->topup_category_id !== null && in_array($offer->topup_category_id, $categories, true));
        }
        $offers = $offers->filter(fn ($offer): bool => $offer->topup_category_id === null);
        $current = $target;
        $seen = [];
        while ($current->id !== $connection->account_id) {
            if (isset($seen[$current->id]) || ! $current->parent_id || $current->type === AccountType::System) {
                return $synced->values();
            }
            $seen[$current->id] = true;
            $grant = $connection->grants->first(fn (DigitalGrant $grant): bool => $grant->target_account_id === $current->id && $grant->from_account_id === $current->parent_id);
            if (! $grant || ! $includePaused && ! $grant->active) {
                return $synced->values();
            }
            $offers = $offers->filter(fn ($offer): bool => in_array($offer->id, $grant->offer_ids, true));
            $allowed = $this->catalog->forAccount($current->id)->pluck('catalog_products.id')->all();
            $offers = $offers->filter(fn ($offer): bool => in_array($offer->product_id, $allowed, true));
            $current = Account::findOrFail($current->parent_id);
        }
        $allowed = $this->catalog->forAccount($connection->account_id)->pluck('catalog_products.id')->all();

        return $synced->concat($offers->filter(fn ($offer): bool => in_array($offer->product_id, $allowed, true)))->values();
    }

    public function recentIndex(mixed $query, User $actor, string $index): mixed
    {
        if (DB::getDriverName() === 'mysql' && $this->accounts($actor)->limit(101)->pluck('accounts.id')->count() > 100) {
            $query->forceIndex($index);
        }

        return $query;
    }

    public function orders(User $actor): Builder
    {
        return DigitalOrder::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
    }

    public function isAssignedProduct(Account $own, int $productId): bool
    {
        $mainIds = DB::table('account_closure')->join('accounts', 'accounts.id', '=', 'ancestor_id')->where('descendant_id', $own->id)->where('accounts.type', '<>', AccountType::System->value)->select('ancestor_id');

        // Pausing a configured digital offer must never turn it into a stock/PIN sale.
        $ancestorIds = DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id');
        if (DigitalOffer::where('product_id', $productId)->where('listed', true)->whereHas('connection', fn ($query) => $query->whereIn('account_id', $ancestorIds)
            ->where(fn ($provider) => $provider->where('provider', '<>', 'topup')->orWhereIn('account_id', $mainIds)))->exists()) {
            return true;
        }
        $main = app(TopupDistribution::class)->main($own);
        if (! $main) {
            return false;
        }
        $allocation = app(TopupAccounting::class)->grant($own);
        $manual = RequestReadCache::remember('DigitalAccess.centralProductGrants:'.$main->id, fn (): Collection => DigitalGrant::where('target_account_id', $main->id)->whereIn('connection_id', DigitalConnection::where('provider', 'topup')->whereHas('account', fn ($account) => $account->where('type', AccountType::System))->select('id'))->get());

        return DigitalOffer::where('product_id', $productId)->where('listed', true)->where(function ($query) use ($allocation, $manual): void {
            $query->whereRaw('1=0');
            if ($allocation) {
                $query->orWhere(fn ($offer) => $offer->where('connection_id', $allocation->connection_id)->whereIn('topup_category_id', $allocation->category_ids));
            }
            foreach ($manual as $grant) {
                $query->orWhere(fn ($offer) => $offer->where('connection_id', $grant->connection_id)->whereIn('id', $grant->offer_ids));
            }
        })->exists();
    }

    public function order(User $actor, int $id, string $permission = 'digital.view', bool $owned = false, bool $lock = false): DigitalOrder
    {
        $this->require($actor, $permission);
        $query = $this->orders($actor);
        $order = ($lock ? $query->lockForUpdate() : $query)->with(['account', 'mainAccount', 'creator', 'product', 'connection.company'])->findOrFail($id);
        if ($owned) {
            abort_unless($actor->membership->account->type === AccountType::Pos && $order->account_id === $actor->membership->account_id, 403, 'تنفيذ الخدمة ووصلها لصاحب نقطة البيع فقط.');
        }

        return $order;
    }
}
