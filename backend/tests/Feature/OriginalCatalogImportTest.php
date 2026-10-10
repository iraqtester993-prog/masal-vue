<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use Database\Seeders\OriginalCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OriginalCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_dictionary_preserves_all_28_categories_aliases_and_unknown_values(): void
    {
        $this->seed(OriginalCatalogSeeder::class);
        $this->assertDatabaseCount('catalog_providers', 1);
        $this->assertDatabaseCount('catalog_products', 28);
        $this->assertDatabaseHas('catalog_providers', ['name' => 'آسياسيل', 'supplier' => '', 'connection' => 'ملفات']);
        $nominal = CatalogProduct::where('legacy_reference', 'asiacell-reference-28-v1:E5K')->firstOrFail();
        $this->assertSame('5,000 دينار', $nominal->name);
        $this->assertSame('5000.00', $nominal->face_value);
        $this->assertSame(['E5K', 'EV5'], $nominal->import_codes);
        $this->assertNull($nominal->minimum_price);
        $this->assertNull($nominal->daily_quantity);
        $package = CatalogProduct::where('legacy_reference', 'asiacell-reference-28-v1:EVD1')->firstOrFail();
        $this->assertSame('5 GIGA weekly', $package->name);
        $this->assertNull($package->face_value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.reference.import']);
        $this->assertDatabaseMissing('catalog_products', ['name' => 'بطاقة تجريبية • 5,000 دينار']);
    }

    public function test_repeated_import_preserves_user_edits_and_does_not_duplicate_records_or_audit(): void
    {
        $this->seed(OriginalCatalogSeeder::class);
        $product = CatalogProduct::firstOrFail();
        $product->update(['name' => 'User edited name', 'minimum_price' => '4700.25', 'version' => 2]);
        $version = (int) DB::table('catalog_meta')->value('version');
        $this->seed(OriginalCatalogSeeder::class);
        $this->assertDatabaseCount('catalog_products', 28);
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertSame($version, (int) DB::table('catalog_meta')->value('version'));
        $this->assertSame('User edited name', $product->fresh()->name);
        $this->assertSame('4700.25', $product->fresh()->minimum_price);
    }

    public function test_collision_with_existing_unbound_category_rolls_back_entire_import(): void
    {
        $provider = CatalogProvider::factory()->create(['name' => 'آسياسيل']);
        CatalogProduct::factory()->create(['name' => '10,000 دينار', 'provider_id' => $provider->id]);
        try {
            $this->seed(OriginalCatalogSeeder::class);
            $this->fail('Import should require collision review.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('requires review', $error->getMessage());
        }
        $this->assertDatabaseCount('catalog_products', 1);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertSame(1, (int) DB::table('catalog_meta')->value('version'));
    }
}
