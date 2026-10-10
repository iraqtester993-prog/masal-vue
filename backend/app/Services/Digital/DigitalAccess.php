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
            Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
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

    public function offers(DigitalConnection $connection, Account $target, bool $includePaused = false): Collection
    {
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

    public function orders(User $actor): Builder
    {
        return DigitalOrder::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
    }

    public function isAssignedProduct(Account $own, int $productId): bool
    {
        $mainIds = DB::table('account_closure')->where('descendant_id', $own->id)->select('ancestor_id');

        // Pausing a configured digital offer must never turn it into a stock/PIN sale.
        return DigitalOffer::where('product_id', $productId)->where('listed', true)->whereHas('connection', fn ($query) => $query->whereIn('account_id', $mainIds))->exists();
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
