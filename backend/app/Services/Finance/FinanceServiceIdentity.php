<?php

namespace App\Services\Finance;

use App\Models\CatalogProvider;
use App\Models\Finance\Wallet;
use App\Support\RequestReadCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceServiceIdentity
{
    public function topupProviderIds(): array
    {
        return RequestReadCache::remember('FinanceServiceIdentity.topupProviderIds', fn (): array => DB::table('catalog_providers as p')
            ->whereExists(fn ($offers) => $offers->selectRaw('1')->from('digital_offers as o')->join('catalog_products as product', 'product.id', '=', 'o.product_id')->join('digital_connections as connection', 'connection.id', '=', 'o.connection_id')->whereColumn('product.provider_id', 'p.id')->where('connection.provider', 'topup'))
            ->orderBy('p.id')->pluck('p.id')->map(fn ($id): int => (int) $id)->all());
    }

    public function legacyServices(): array
    {
        return array_map(fn (int $id): string => 'api:'.$id, $this->topupProviderIds());
    }

    public function isLegacy(?string $service): bool
    {
        return $service !== null && str_starts_with($service, 'api:') && in_array($service, $this->legacyServices(), true);
    }

    public function family(string $service): array
    {
        return $service === 'topup' || $this->isLegacy($service) ? ['topup', ...$this->legacyServices()] : [$service];
    }

    public function metadata(?string $service): array
    {
        if ($service === null) {
            return [];
        }
        $legacy = $this->isLegacy($service);

        return ['service' => $legacy ? 'topup' : $service, 'source_service' => $service, 'legacy_service' => $legacy, 'source_label' => $legacy ? 'رصيد سابق' : null];
    }

    public function present(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->present($item);
            }
        }
        $source = $value['source_service'] ?? $value['service'] ?? null;

        return is_string($source) && $this->isLegacy($source) ? array_replace($value, $this->metadata($source)) : $value;
    }

    public function requireCurrent(string $service): void
    {
        if ($this->isLegacy($service)) {
            throw ValidationException::withMessages(['service' => 'هذه محفظة Topup سابقة قيد المطابقة. استخدم Topup للتمويل الجديد؛ يبقى السجل والاسترجاع والتحصيل السابق محفوظًا.']);
        }
    }

    public function providerName(?CatalogProvider $provider): ?string
    {
        return $provider && in_array((int) $provider->id, $this->topupProviderIds(), true) ? 'Topup' : $provider?->name;
    }

    public function serviceSql(string $column): string
    {
        $aliases = array_map(fn (string $service): string => DB::connection()->getPdo()->quote($service), $this->legacyServices());

        return $aliases ? 'CASE WHEN '.$column.' IN ('.implode(',', $aliases).") THEN 'topup' ELSE ".$column.' END' : $column;
    }

    public function providerSql(string $id, string $name): string
    {
        $ids = $this->topupProviderIds();

        return $ids ? 'CASE WHEN '.$id.' IN ('.implode(',', $ids).") THEN 'Topup' ELSE ".$name.' END' : $name;
    }

    public function legacySummary(mixed $accountIds, int $ownId, array $mapIds, string $scope = 'filtered_accounts', array $filters = []): array
    {
        $base = ['service' => 'topup', 'currency' => 'IQD', 'status' => 'unreconciled', 'account_scope' => $scope, 'accounts_scope' => $scope === 'visible_accounts' ? 'own_account' : 'current_page'];
        $aliases = $this->legacyServices();
        if (! $aliases || isset($filters['currency']) && $filters['currency'] !== 'IQD') {
            return $base + ['amount' => '0.00', 'held' => '0.00', 'own_amount' => '0.00', 'own_held' => '0.00', 'accounts' => []];
        }
        $query = Wallet::where('kind', 'account')->where('currency', 'IQD')->whereIn('service', $aliases)->whereIn('account_id', $accountIds);
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Baghdad')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Baghdad')->addDay()->startOfDay()->utc());
        }
        if (! empty($filters['q']) && stripos('Topup', $filters['q']) === false) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($names) => $names->whereRaw("service LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('account', fn ($accounts) => $accounts->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])));
        }
        $totals = (clone $query)->selectRaw('COALESCE(SUM(balance_minor),0) AS amount, COALESCE(SUM(held_minor),0) AS held, COALESCE(SUM(CASE WHEN account_id=? THEN balance_minor ELSE 0 END),0) AS own_amount, COALESCE(SUM(CASE WHEN account_id=? THEN held_minor ELSE 0 END),0) AS own_held', [$ownId, $ownId])->first();
        $accounts = (clone $query)->whereIn('account_id', array_unique([...$mapIds, $ownId]))->groupBy('account_id')->selectRaw('account_id, SUM(balance_minor) AS amount, SUM(held_minor) AS held')->get();

        return $base + [
            'amount' => Money::decimal((int) $totals->amount), 'held' => Money::decimal((int) $totals->held), 'own_amount' => Money::decimal((int) $totals->own_amount), 'own_held' => Money::decimal((int) $totals->own_held),
            'accounts' => $accounts->map(fn ($row): array => ['account_id' => (int) $row->account_id, 'amount' => Money::decimal((int) $row->amount), 'held' => Money::decimal((int) $row->held)])->all()];
    }
}
