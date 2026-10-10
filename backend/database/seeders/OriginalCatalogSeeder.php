<?php

namespace Database\Seeders;

use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OriginalCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entries = json_decode(file_get_contents(__DIR__.'/data/original-catalog.json'), true, 64, JSON_THROW_ON_ERROR);
        DB::transaction(function () use ($entries): void {
            DB::table('catalog_meta')->where('id', 1)->lockForUpdate()->firstOrFail();
            $provider = CatalogProvider::firstOrCreate(['name' => 'آسياسيل'], ['supplier' => '', 'connection' => 'ملفات', 'status' => 'active', 'version' => 1]);
            $created = 0;
            $order = (int) CatalogProduct::max('display_order');
            foreach ($entries as $entry) {
                $key = 'asiacell-reference-28-v1:'.$entry['codes'][0];
                if (CatalogProduct::where('legacy_reference', $key)->exists()) {
                    continue;
                }
                $name = $entry['face'] !== null ? $entry['label'].' دينار' : $entry['label'];
                if (CatalogProduct::where('provider_id', $provider->id)->where('name', $name)->exists()) {
                    throw new \RuntimeException('Existing unbound category name; reference import requires review.');
                }
                $product = new CatalogProduct;
                $product->forceFill(['legacy_reference' => $key, 'provider_id' => $provider->id, 'name' => $name, 'kind' => 'محلية', 'currency' => 'IQD', 'face_value' => $entry['face'], 'minimum_price' => null, 'daily_limit_type' => '', 'daily_quantity' => null, 'daily_amount' => null, 'field_policy' => ['pin' => 'required', 'expiry' => 'required', 'serial' => 'required', 'cvc' => 'unused', 'reference' => 'unused'], 'extra_fields' => [], 'import_codes' => $entry['codes'], 'display_order' => ++$order, 'receipt_language' => '', 'receipt_width' => 80, 'receipt_header' => '', 'receipt_footer' => '', 'allowed_cities' => [], 'status' => 'active', 'version' => 1])->save();
                $created++;
            }
            if ($created || $provider->wasRecentlyCreated) {
                DB::table('catalog_meta')->where('id', 1)->increment('version');
                $request = Request::create('/catalog/reference-import', 'POST');
                $request->attributes->set('portal', 'admin');
                app(AuditLogger::class)->record('catalog.reference.import', $request, null, null, ['reference' => 'asiacell-reference-28-v1', 'created_products' => $created, 'provider_id' => $provider->id]);
            }
        });
    }
}
