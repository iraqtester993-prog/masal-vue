<?php

namespace App\Services\Digital;

use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TopupCatalog
{
    public function __construct(private DigitalAccess $access, private DigitalConfiguration $configuration, private AuditLogger $audit) {}

    public function synchronize(User $actor, int $id, int $version, Request $request): array
    {
        $this->access->owner($actor);
        $connection = $this->access->connection($actor, $id, 'integrations.edit');
        abort_unless($connection->provider === 'topup', 422);
        $this->access->authority->version($connection, $version);
        $hash = DigitalConfiguration::credentialHash($connection->credential);
        $result = ['balance' => 'failed', 'catalog' => 'failed', 'category_count' => null];
        if ($request->boolean('catalog_only')) {
            $result['balance'] = 'skipped';
        } else {
            try {
                $this->configuration->balance($actor, $id, $request);
                $result['balance'] = 'ok';
            } catch (\Throwable) {
                $result['balance_message'] = 'تعذر تحديث الرصيد من الشركة؛ القراءة السابقة لا تمثل رصيدًا محدثًا.';
            }
        }
        try {
            $catalog = $this->configuration->catalog($actor, $id, $version, $request);
            $this->import($actor, $connection, $hash, $version, $catalog['id'], $request);
            $result['category_count'] = count($catalog['catalog']);
            $result['catalog'] = $result['category_count'] === 0 ? 'empty' : 'ok';
            $result['catalog_message'] = $result['category_count'] === 0 ? 'نجح الاستعلام، لكن الشركة لم ترجع أي فئة لهذا التوكن. التعبئة غير متاحة حتى ترجع الشركة فئات معتمدة.' : 'تم جلب فئات الشركة وربطها بالتوزيع والتعبئة.';
            $result['excluded_count'] = $catalog['excluded_count'] ?? 0;
            if ($result['excluded_count'] > 0) {
                $result['catalog_message'] .= ' استُبعدت '.$result['excluded_count'].' سجلات مجموعات أو فئات دون سعر صالح للبيع.';
            }
        } catch (\Throwable) {
            $result['catalog_message'] = 'تعذر تحديث فئات الشركة؛ لم تُنشأ فئات بديلة. أعد الاستعلام.';
        }
        $current = $this->access->connection($actor, $id, 'integrations.edit');
        abort_unless(hash_equals($hash, DigitalConfiguration::credentialHash($current->credential)), 409, 'تغير التوكن أثناء الاستعلام؛ حدّث الربط.');

        return $this->configuration->dto($current, $actor) + ['sync' => $result];
    }

    private function import(User $actor, DigitalConnection $connection, string $hash, int $version, int $snapshotId, Request $request): void
    {
        DB::transaction(function () use ($actor, $connection, $hash, $version, $snapshotId, $request): void {
            app(MutationGuard::class)->lock($actor, [$connection->account_id], 'integrations.edit');
            $current = $this->access->connection($actor, $connection->id, 'integrations.edit', true);
            $this->access->authority->version($current, $version);
            abort_unless(hash_equals($hash, DigitalConfiguration::credentialHash($current->credential)), 409);
            $snapshot = CatalogSnapshot::where('connection_id', $current->id)->where('credential_hash', $hash)->findOrFail($snapshotId);
            abort_if(CatalogSnapshot::where('connection_id', $current->id)->where('credential_hash', $hash)->where('id', '>', $snapshotId)->exists(), 409);
            $ids = [];
            $provider = null;
            foreach ($snapshot->catalog as $row) {
                $category = TopupCategory::firstOrNew(['connection_id' => $current->id, 'type' => $row['type'], 'remote_id' => $row['remote_id']]);
                $wasAvailable = $category->exists && $category->active;
                $cost = DigitalMoney::minor($row['cost']);
                $retail = ! $category->exists || $category->retail_minor === $category->cost_minor ? DigitalMoney::minor($row['retail']) : $category->retail_minor;
                $category->fill(['catalog_snapshot_id' => $snapshotId, 'name' => mb_substr($row['remote_name'], 0, 160), 'cost_minor' => $cost, 'retail_minor' => $retail, 'active' => true, 'version' => $category->exists ? $category->version + 1 : 1])->save();
                $offer = DigitalOffer::where('connection_id', $current->id)->where('remote_id', $row['remote_id'])->where('type', $row['type'])->whereNull('manual_category_version')->lockForUpdate()->first();
                if (! $offer) {
                    $provider ??= CatalogProvider::firstOrCreate(['name' => 'آسياسيل · التعبئة المباشرة'], ['supplier' => 'Masal', 'connection' => 'API', 'status' => 'active']);
                    $reference = 'topup:'.substr(hash('sha256', $row['type'].':'.$row['remote_id']), 0, 48);
                    $product = CatalogProduct::where('legacy_reference', $reference)->first();
                    if (! $product) {
                        $name = mb_substr($row['remote_name'], 0, 130);
                        if (CatalogProduct::where('provider_id', $provider->id)->where('name', $name)->exists()) {
                            $name .= ' · '.$category->id;
                        }
                        $product = CatalogProduct::create(['name' => $name, 'provider_id' => $provider->id, 'kind' => 'محلية', 'currency' => 'IQD', 'daily_limit_type' => 'quantity', 'field_policy' => [], 'extra_fields' => [], 'import_codes' => [], 'allowed_cities' => [], 'status' => 'active']);
                        $product->forceFill(['legacy_reference' => $reference])->save();
                    }
                    $offer = new DigitalOffer(['connection_id' => $current->id, 'product_id' => $product->id, 'retail_minor' => $category->retail_minor]);
                }
                if ($offer->topup_category_id !== null) {
                    $offer->retail_minor = $category->retail_minor;
                }
                $offer->fill(['topup_category_id' => $category->id, 'catalog_snapshot_id' => $snapshotId, 'remote_id' => $row['remote_id'], 'remote_key' => hash('sha256', $row['remote_id'].':'), 'remote_name' => $row['remote_name'], 'type' => $row['type'], 'cost_minor' => $category->cost_minor, 'active' => $offer->exists && $wasAvailable ? $offer->active : true, 'listed' => true, 'version' => $offer->exists ? $offer->version + 1 : 1])->save();
                $ids[] = $category->id;
            }
            TopupCategory::where('connection_id', $current->id)->whereNotIn('id', $ids)->update(['active' => false, 'version' => DB::raw('version + 1')]);
            DigitalOffer::where('connection_id', $current->id)->whereNotNull('topup_category_id')->whereNull('manual_category_version')->whereNotIn('topup_category_id', $ids)->update(['active' => false, 'version' => DB::raw('version + 1')]);
            if ($ids && $current->account->type === \App\Enums\AccountType::MainAgent) {
                TopupGrant::firstOrCreate(['target_account_id' => $current->account_id], ['main_account_id' => $current->account_id, 'from_account_id' => $current->account->parent_id, 'category_ids' => $ids, 'active' => true, 'version' => 1]);
            }
            $current->update(['version' => $current->version + 1]);
            $this->audit->record('topup.catalog.synchronized', $request, $actor, $current->account_id, ['connection_id' => $current->id, 'snapshot_id' => $snapshotId, 'category_count' => count($ids)]);
        });
    }
}
