<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\CatalogProvider;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\FinanceOperations;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DigitalConfiguration
{
    public function __construct(private DigitalAccess $access, private ProviderGateway $gateway, private FinanceOperations $operations, private AuditLogger $audit) {}

    public static function credentialHash(?string $credential): string
    {
        return hash_hmac('sha256', (string) $credential, (string) config('app.key'));
    }

    public function offerDto(DigitalOffer $offer, User $actor): array
    {
        $data = ['id' => $offer->id, 'product_id' => $offer->product_id, 'product_provider_id' => $offer->product->provider_id, 'product_provider_name' => $offer->product->provider->name, 'name' => $offer->topup_category_id ? $offer->remote_name : $offer->product->name, 'remote_id' => $offer->remote_id, 'remote_name' => $offer->remote_name, 'province_id' => $offer->province_id, 'province' => $offer->province_name, 'bein_province_id' => $offer->bein_province_id, 'package_type' => $offer->package_type, 'type' => $offer->type, 'retail' => Money::decimal($offer->retail_minor), 'active' => $offer->active, 'version' => $offer->version, 'catalog_snapshot_id' => $offer->catalog_snapshot_id];
        if ($offer->connection->provider === 'topup' && $actor->membership->account->type !== AccountType::System) {
            $data['retail'] = Money::decimal(app(TopupAccounting::class)->retail($offer, $actor->membership->account));
        }
        if ($actor->membership->account->type !== AccountType::Pos && in_array('data.cost', $actor->membership->permissions(), true)) {
            $data['cost'] = $offer->cost_minor === null ? null : Money::decimal($offer->cost_minor);
        }

        return $data;
    }

    public function dto(DigitalConnection $connection, User $actor): array
    {
        $connection->loadMissing(['account', 'company', 'offers.product.provider', 'grants.target']);
        $system = $actor->membership->account->type === AccountType::System;
        $own = $actor->membership->account;
        $offers = $system || $connection->account_id === $own->id ? $connection->offers->where('listed', true) : $this->access->offers($connection, $own, true);
        if ($connection->provider === 'topup') {
            $offers = $offers->filter(fn ($offer): bool => $offer->manual_category_version === null && ($offer->topup_category_id === null || $offer->catalog_snapshot_id !== null));
        }
        $grantTargets = $this->access->accounts($actor)->pluck('id')->all();
        $data = ['id' => $connection->id, 'account_id' => $connection->account_id, 'account_name' => $connection->account->name, 'provider_id' => $connection->provider_id, 'provider_name' => $connection->company?->name ?? ($connection->provider === 'rabiaa' ? 'الرابعة' : 'Topup'), 'provider' => $connection->provider, 'active' => $connection->active, 'version' => $connection->version, 'credential_present' => (bool) $connection->credential, 'gateway' => $this->gateway->status($connection), 'bein_provinces' => $connection->bein_provinces ?? [], 'offers' => $offers->map(fn (DigitalOffer $offer): array => $this->offerDto($offer, $actor))->values()->all(), 'grants' => $connection->grants->filter(fn ($grant): bool => in_array($grant->target_account_id, $grantTargets, true) && ($system || $grant->from_account_id === $own->id))->map(fn ($grant): array => $this->grantDto($grant))->values()->all(), 'updated_at' => $connection->updated_at->toISOString()];
        if ($own->type !== AccountType::Pos) {
            $data += ['company_balance' => $connection->company_balance_minor === null ? null : Money::decimal($connection->company_balance_minor), 'balance_updated_at' => $connection->balance_updated_at?->toISOString()];
            if ($connection->provider === 'topup') {
                if ($system) {
                    $free = app(TopupAccounting::class)->free($connection);
                    $data['unallocated_balance'] = $free === null ? null : Money::decimal($free);
                } elseif ($grant = app(TopupAccounting::class)->grant($own)) {
                    $data['company_balance'] = Money::decimal($grant->balance_minor);
                    $data['allocation_available'] = Money::decimal($grant->balance_minor - $grant->held_minor);
                    $data['allocation_spent'] = Money::decimal($grant->spent_minor);
                }
            }
        }
        if ($connection->provider === 'topup' && ($system || $connection->account_id === $own->id)) {
            $snapshot = CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', self::credentialHash($connection->credential))->latest('id')->first();
            $data += ['catalog_count' => $snapshot ? count($snapshot->catalog) : null, 'catalog_updated_at' => $snapshot?->created_at?->toISOString(), 'excluded_catalog' => $snapshot?->excluded_catalog, 'excluded_count' => $snapshot?->excluded_catalog === null ? null : count($snapshot->excluded_catalog)];
        }
        if (in_array($own->type, [AccountType::MainAgent, AccountType::SubAgent, AccountType::SubBranch], true)) {
            $data['effective_offer_ids'] = $this->access->offers($connection, $own)->pluck('id')->all();
        }

        return $data;
    }

    public function grantDto(DigitalGrant $grant): array
    {
        return ['id' => $grant->id, 'connection_id' => $grant->connection_id, 'from_account_id' => $grant->from_account_id, 'target_account_id' => $grant->target_account_id, 'target_name' => $grant->target?->name, 'kind' => $grant->target?->type === AccountType::Pos ? 'pos' : 'agent', 'offer_ids' => $grant->offer_ids, 'active' => $grant->active, 'version' => $grant->version];
    }

    public function save(User $actor, ?int $id, array $data, Request $request): array
    {
        $this->access->owner($actor);

        try {
            return $this->operations->execute($actor, 'integrations.edit', $data + ['connection_id' => $id], function () use ($actor, $id, $data, $request): array {
                $account = $this->access->accounts($actor)->where('type', $data['provider'] === 'topup' && $data['account_id'] === $actor->membership->account_id ? AccountType::System : AccountType::MainAgent)->lockForUpdate()->findOrFail($data['account_id']);
                abort_unless($account->isOperational(), 409, 'اختر وكيلًا رئيسيًا مفعّلًا.');
                $connection = $id ? $this->access->connection($actor, $id, 'integrations.edit', true) : null;
                $providerId = array_key_exists('provider_id', $data) ? $data['provider_id'] : $connection?->provider_id;
                $provider = $providerId === null ? null : CatalogProvider::where('status', 'active')->lockForUpdate()->findOrFail($providerId);
                if ($connection) {
                    $this->access->authority->version($connection, $data['version']);
                    abort_unless($connection->account_id === $account->id && $connection->provider === $data['provider'] && $connection->provider_id === $provider?->id, 409, 'هوية الربط لا تتغير بعد إنشائه؛ أضف ربطًا جديدًا للحساب الآخر.');
                } else {
                    abort_unless($data['version'] === 0, 409);
                    abort_if($data['provider'] !== 'topup' && DigitalConnection::where('account_id', $account->id)->where('provider', $data['provider'])->exists(), 409, 'يوجد ربط لهذه الخدمة مع الوكيل؛ عدّل الربط الحالي.');
                    $connection = DigitalConnection::create(['account_id' => $account->id, 'provider_id' => $provider?->id, 'provider' => $data['provider'], 'active' => false, 'version' => 1]);
                }
                $credential = $data['credential'] ?? $connection->credential;
                if ($data['provider'] === 'topup') {
                    $credential = $credential === null ? null : trim($credential);
                    if ($credential !== null && $credential !== '' && DigitalConnection::where('topup_credential_hash', self::credentialHash($credential))->whereKeyNot($connection->id)->exists()) {
                        throw ValidationException::withMessages(['credential' => 'هذا التوكن محفوظ مسبقًا؛ وزّع رصيده وفئاته من الربط الحالي.']);
                    }
                }
                abort_if($credential !== $connection->credential && $connection->exists && DigitalOrder::where('connection_id', $connection->id)->where('reservation_active', true)->exists(), 409, 'تحقق من العمليات المعلّقة قبل تغيير توكن الشركة.');
                $snapshot = isset($data['catalog_snapshot_id']) ? CatalogSnapshot::where('connection_id', $connection->id)->where('credential_hash', self::credentialHash($credential))->findOrFail($data['catalog_snapshot_id']) : null;
                $products = $this->access->catalog->forAccount($account->id)->whereIn('catalog_products.id', $this->access->catalog->products($actor)->select('catalog_products.id'))->where('status', 'active')->where('currency', 'IQD')->whereHas('provider', fn ($query) => $query->where('status', 'active'))->get()->keyBy('id');
                $oldOffers = $connection->offers()->get()->keyBy('product_id');
                $prepared = [];
                $productIds = [];
                $remoteKeys = [];
                foreach ($data['offers'] as $offer) {
                    $synced = $oldOffers->get($offer['product_id']);
                    if ($synced !== null && $synced->topup_category_id !== null) {
                        abort_unless(($offer['id'] ?? null) === $synced->id && ($offer['remote_id'] ?? '') === $synced->remote_id, 422);

                        abort_unless($synced->manual_category_version === null && CatalogSnapshot::whereKey($synced->catalog_snapshot_id)->where('credential_hash', self::credentialHash($connection->credential))->exists(), 422, 'اجلب فئات الشركة بالتوكن الحالي.');
                        $retail = DigitalMoney::minor($offer['retail'], 'retail');
                        if ($retail !== $synced->retail_minor || $offer['active'] !== $synced->active) {
                            $synced->update(['retail_minor' => $retail, 'active' => $offer['active'], 'version' => $synced->version + 1]);
                            TopupCategory::whereKey($synced->topup_category_id)->update(['retail_minor' => $retail, 'version' => DB::raw('version + 1')]);
                        }

                        continue;
                    }
                    $product = $products->get($offer['product_id']);
                    abort_unless($product && ! in_array($product->id, $productIds, true), 422, 'الفئة غير مسموحة للوكيل أو موقوفة أو مكررة أو بعملة أخرى.');
                    $productIds[] = $product->id;
                    $old = $oldOffers->get($product->id);
                    abort_if(isset($offer['id']) && (! $old || $old->id !== $offer['id']), 404);
                    $remote = trim($offer['remote_id'] ?? '');
                    $province = trim($offer['province_id'] ?? '');
                    $catalogRow = null;
                    if ($remote !== '') {
                        if ($snapshot) {
                            $catalogRow = collect($snapshot->catalog)->first(fn ($row): bool => $row['remote_id'] === $remote && $row['province_id'] === $province);
                        } elseif ($old && $old->remote_id === $remote && (string) $old->province_id === $province && $old->cost_minor > 0 && $old->catalog_snapshot_id && CatalogSnapshot::whereKey($old->catalog_snapshot_id)->where('credential_hash', self::credentialHash($credential))->exists()) {
                            $catalogRow = ['remote_id' => $remote, 'remote_name' => $old->remote_name, 'province_id' => $province, 'province' => $old->province_name, 'package_type' => $old->package_type, 'type' => $old->type, 'cost' => Money::decimal($old->cost_minor)];
                        }
                        abort_unless($catalogRow, 422, 'اجلب فئات الشركة بهذا التوكن ثم اربط الفئة من القائمة الفعلية.');
                        $remoteKey = hash('sha256', $remote.':'.$province);
                        abort_if(in_array($remoteKey, $remoteKeys, true), 422, 'لا تكرر فئة الشركة في الربط.');
                        $remoteKeys[] = $remoteKey;
                    }
                    $retail = DigitalMoney::minor($offer['retail'], 'retail');
                    abort_if($product->minimum_price !== null && $retail < Money::minor($product->minimum_price), 422, 'سعر البيع أقل من الحد الأدنى المعتمد للفئة.');
                    $prepared[] = ['product_id' => $product->id, 'catalog_snapshot_id' => $catalogRow ? ($snapshot?->id ?? $old->catalog_snapshot_id) : null, 'remote_id' => $remote ?: null, 'remote_key' => $remote === '' ? null : hash('sha256', $remote.':'.$province), 'remote_name' => $catalogRow['remote_name'] ?? null, 'province_id' => $province ?: null, 'province_name' => $catalogRow['province'] ?? null, 'package_type' => $catalogRow['package_type'] ?? 'standard', 'type' => $catalogRow['type'] ?? ($connection->provider === 'topup' ? 'topup' : 'voucher'), 'bein_province_id' => $offer['bein_province_id'] ?? null, 'cost_minor' => $catalogRow ? DigitalMoney::minor($catalogRow['cost'], 'cost') : null, 'retail_minor' => $retail, 'active' => $offer['active'], 'listed' => true];
                }
                $connection->offers()->whereNull('topup_category_id')->update(['remote_key' => null, 'active' => false, 'listed' => false, 'version' => DB::raw('version + 1')]);
                foreach ($prepared as $offer) {
                    $existing = $oldOffers->get($offer['product_id']);
                    if ($existing) {
                        $version = $existing->version + 1;
                        $existing->refresh()->update($offer + ['version' => $version]);
                    } else {
                        $connection->offers()->create($offer + ['version' => 1]);
                    }
                }
                if ($credential !== $connection->credential) {
                    $connection->company_balance_minor = null;
                    $connection->balance_updated_at = null;
                }
                $connection->update(['credential' => $credential, 'active' => $data['active'], 'bein_provinces' => $snapshot?->bein_provinces ?? $connection->bein_provinces, 'version' => $id ? $connection->version + 1 : 1]);
                $this->audit->record('integrations.edit', $request, $actor, $account->id, ['connection_id' => $connection->id, 'provider' => $connection->provider, 'offer_count' => count($prepared), 'active' => $connection->active, 'credential_changed' => array_key_exists('credential', $data)]);
                $connection->unsetRelations();

                return $this->dto($connection, $actor);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'topup_credential_hash')) {
                throw ValidationException::withMessages(['credential' => 'هذا التوكن مرتبط بوكيل آخر؛ لا يمكن تخصيص توكن Topup لأكثر من وكيل رئيسي.']);
            }
            throw $exception;
        }
    }

    public function catalog(User $actor, int $id, int $version, Request $request): array
    {
        $this->access->owner($actor);
        $connection = $this->access->connection($actor, $id, 'integrations.edit');
        $this->access->authority->version($connection, $version);
        $credentialHash = self::credentialHash($connection->credential);
        $result = $this->gateway->call($connection, 'catalog');
        $rows = $connection->provider === 'topup' ? ($result['products'] ?? null) : ($result['data'] ?? null);
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= 5000, 422, 'قائمة الشركة لا تطابق عقد الربط.');
        $directTopup = $this->gateway->directTopup($connection);
        $catalog = $directTopup ? $result['catalog'] : [];
        $keys = [];
        foreach ($directTopup ? [] : $rows as $row) {
            abort_unless(is_array($row) && is_string($row['title'] ?? null) && trim($row['title']) !== '' && mb_strlen($row['title']) <= 200, 422, 'اسم فئة الشركة غير صحيح.');
            $remote = $row[$connection->provider === 'topup' ? 'product_id' : 'catalogId'] ?? null;
            $remote = is_int($remote) ? (string) $remote : $remote;
            abort_unless(is_string($remote) && strlen($remote) >= 1 && strlen($remote) <= 100 && ($connection->provider !== 'rabiaa' || ctype_digit($remote)), 422, 'معرف فئة الشركة غير صحيح.');
            abort_if(isset($row['currency']) && $row['currency'] !== 'IQD', 422, 'عملة فئة الشركة لا تطابق الدينار العراقي.');
            $province = (string) ($row['provinceId'] ?? '');
            abort_if($province !== '' && (! ctype_digit($province) || strlen($province) > 30), 422, 'معرف المحافظة غير صحيح.');
            $key = hash('sha256', $remote.':'.$province);
            abort_if(isset($keys[$key]), 422, 'فئة الشركة مكررة في الاستجابة.');
            $keys[$key] = true;
            $catalog[] = ['key' => $key, 'remote_id' => $remote, 'remote_name' => trim($row['title']), 'province_id' => $province, 'province' => mb_substr((string) ($row['province'] ?? ''), 0, 100), 'package_type' => $connection->provider === 'rabiaa' && ($row['packageType'] ?? '') === 'premium' ? 'premium' : 'standard', 'type' => $connection->provider === 'topup' && in_array($row['type'] ?? '', ['topup', 'bundle', 'bill'], true) ? $row['type'] : ($connection->provider === 'topup' ? 'topup' : 'voucher'), 'cost' => Money::decimal(DigitalMoney::minor($row[$connection->provider === 'topup' ? 'price' : 'vendorPrice'] ?? null, 'cost')), 'retail' => Money::decimal(DigitalMoney::minor($row[$connection->provider === 'topup' ? 'price' : 'publicPrice'] ?? null, 'retail'))];
        }
        $provinces = $result['beinProvinces'] ?? [];
        abort_unless(is_array($provinces) && array_is_list($provinces) && count($provinces) <= 200, 422);
        $provinces = array_map(function ($province): array {
            abort_unless(is_array($province) && ctype_digit((string) ($province['id'] ?? '')) && is_string($province['name'] ?? null) && mb_strlen($province['name']) <= 100, 422, 'قائمة محافظات المشترك غير صحيحة.');

            return ['id' => (string) $province['id'], 'name' => $province['name']];
        }, $provinces);

        $excluded = $directTopup ? $result['excluded_count'] : 0;
        $excludedCatalog = $directTopup ? $result['excluded_catalog'] : [];

        return DB::transaction(function () use ($actor, $id, $version, $credentialHash, $catalog, $provinces, $request, $excluded, $excludedCatalog): array {
            app(MutationGuard::class)->lock($actor, [], 'integrations.edit');
            $current = $this->access->connection($actor, $id, 'integrations.edit', true);
            $this->access->authority->version($current, $version);
            abort_unless(self::credentialHash($current->credential) === $credentialHash, 409, 'تغير التوكن أثناء جلب القائمة.');
            $snapshot = CatalogSnapshot::create(['connection_id' => $id, 'actor_id' => $actor->id, 'credential_hash' => $credentialHash, 'catalog' => $catalog, 'bein_provinces' => $provinces, 'created_at' => now()]);
            DB::table('digital_catalog_exclusions')->insert(['snapshot_id' => $snapshot->id, 'excluded_catalog' => json_encode($excludedCatalog, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->audit->record('integrations.catalog', $request, $actor, $current->account_id, ['connection_id' => $id, 'snapshot_id' => $snapshot->id, 'category_count' => count($catalog)]);
            $visibleCatalog = $catalog;
            if (! in_array('data.cost', $actor->membership->permissions(), true)) {
                foreach ($visibleCatalog as &$row) {
                    unset($row['cost']);
                } unset($row);
            }

            return ['id' => $snapshot->id, 'catalog' => $visibleCatalog, 'excluded_count' => $excluded, 'bein_provinces' => $provinces, 'connection_version' => $current->version];
        });
    }

    public function balance(User $actor, int $id, Request $request): array
    {
        $this->access->require($actor, 'digital.view');
        abort_if($actor->membership->account->type === AccountType::Pos, 403);
        $connection = $this->access->connection($actor, $id);
        $hash = self::credentialHash($connection->credential);
        $result = $this->gateway->call($connection, 'inventory');
        $balance = DigitalMoney::minor($result['remaining_balance'] ?? null, 'remaining_balance', true);

        return DB::transaction(function () use ($actor, $connection, $hash, $balance, $request): array {
            app(MutationGuard::class)->lock($actor, [], 'app');
            $current = $this->access->connection($actor, $connection->id, 'digital.view', true);
            abort_unless(self::credentialHash($current->credential) === $hash, 409, 'تغير التوكن أثناء تحديث الرصيد.');
            $current->update(['company_balance_minor' => $balance, 'balance_updated_at' => now()]);
            $this->audit->record('digital.balance', $request, $actor, $current->account_id, ['connection_id' => $current->id, 'provider' => $current->provider]);

            return $this->dto($current, $actor);
        });
    }

    public function grant(User $actor, int $connectionId, int $targetId, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'digital.assign');
        abort_unless(in_array($own->type, [AccountType::MainAgent, AccountType::SubAgent, AccountType::SubBranch], true), 403, 'توزيع الفئات من حساب الوكيل إلى تابعه المباشر فقط.');

        return $this->operations->execute($actor, 'digital.assign', $data + ['connection_id' => $connectionId, 'target_account_id' => $targetId], function () use ($actor, $connectionId, $targetId, $data, $request): array {
            $own = $this->access->own($actor, 'digital.assign', true);
            $target = $this->access->accounts($actor)->where('parent_id', $own->id)->lockForUpdate()->findOrFail($targetId);
            abort_unless($target->isOperational(), 409, 'اختر تابعًا مفعّلًا ضمن نطاقك.');
            $connection = $this->access->connection($actor, $connectionId, 'digital.assign', true);
            $grant = DigitalGrant::where('connection_id', $connectionId)->where('target_account_id', $targetId)->lockForUpdate()->first();
            $available = $this->access->offers($connection, $own)->pluck('id')->all();
            $unchanged = $grant && $grant->offer_ids === $data['offer_ids'];
            abort_if(! $unchanged && array_diff($data['offer_ids'], $available), 422, 'لا يمكنك منح فئة غير مسموحة لك.');
            if ($grant) {
                $this->access->authority->version($grant, $data['version']);
                abort_unless($grant->from_account_id === $own->id, 409);
            } else {
                abort_unless($data['version'] === 0, 409);
            }
            $grant = DigitalGrant::updateOrCreate(['connection_id' => $connectionId, 'target_account_id' => $targetId], ['from_account_id' => $own->id, 'offer_ids' => $data['offer_ids'], 'active' => $data['active'], 'version' => $grant ? $grant->version + 1 : 1]);
            $this->audit->record('digital.assign', $request, $actor, $targetId, ['connection_id' => $connectionId, 'grant_id' => $grant->id, 'offer_ids' => $data['offer_ids'], 'active' => $grant->active]);

            return $this->grantDto($grant);
        });
    }
}
