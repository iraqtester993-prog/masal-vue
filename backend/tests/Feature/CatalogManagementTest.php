<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function admin(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
    }

    private function productInput(): array
    {
        return ['name' => 'Original Category', 'provider_id' => CatalogProvider::factory()->create()->id, 'kind' => 'محلية', 'face_value' => '5000.00', 'currency' => 'IQD', 'minimum_price' => '4500.00', 'daily_limit_type' => 'quantity', 'daily_quantity' => 100, 'daily_amount' => null, 'field_policy' => ['pin' => 'required', 'expiry' => 'required', 'serial' => 'unused', 'cvc' => 'unused', 'reference' => 'unused'], 'extra_fields' => [], 'display_order' => 0, 'import_codes' => ['evs-e5k'], 'receipt_language' => '', 'receipt_width' => 80, 'receipt_header' => 'النص أعلى البطاقة', 'receipt_footer' => 'النص أسفل البطاقة', 'allowed_cities' => ['بغداد']];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3H8AAAAASUVORK5CYII='));
    }

    public function test_categories_reject_dollars_without_creating_a_product(): void
    {
        $this->admin();
        $input = array_replace($this->productInput(), ['currency' => 'USD']);
        $this->postJson('/api/v1/catalog/products', $input)->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('catalog_products', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'products.create']);
    }

    public function test_image_size_limit_accepts_exactly_700000_bytes_and_rejects_the_next_byte(): void
    {
        Storage::fake('local');
        $this->admin();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAEUlEQVR4nGPgmLH1PwgzwBgATd4JUcKJ7HcAAAAASUVORK5CYII=');
        $payload = json_encode(['name' => 'Boundary provider', 'supplier' => 'Supplier', 'connection' => 'ملفات']);
        $accepted = UploadedFile::fake()->createWithContent('boundary.png', $png.str_repeat(' ', 700000 - strlen($png)));
        $this->post('/api/v1/catalog/providers', ['payload' => $payload, 'image' => $accepted], ['Accept' => 'application/json'])->assertCreated();
        $tooLarge = UploadedFile::fake()->createWithContent('large.png', $png.str_repeat(' ', 700001 - strlen($png)));
        $this->post('/api/v1/catalog/providers', ['payload' => $payload, 'image' => $tooLarge], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertSame(1, CatalogProvider::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_multipart_method_override_replaces_then_removes_the_private_image(): void
    {
        $this->admin();
        Storage::fake('local');
        $provider = $this->post('/api/v1/catalog/providers', ['payload' => json_encode(['name' => 'Image provider', 'supplier' => 'Supplier', 'connection' => 'ملفات']), 'image' => $this->image()], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $before = CatalogProvider::findOrFail($provider['id'])->image_path;
        $this->post('/api/v1/catalog/providers/'.$provider['id'], ['_method' => 'PATCH', 'payload' => json_encode(['name' => 'Image provider', 'supplier' => 'Supplier', 'connection' => 'API', 'version' => 1]), 'image' => $this->image()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 2);
        $after = CatalogProvider::findOrFail($provider['id'])->image_path;
        $this->assertNotSame($before, $after);
        Storage::disk('local')->assertMissing($before);
        Storage::disk('local')->assertExists($after);
        $this->patchJson('/api/v1/catalog/providers/'.$provider['id'], ['name' => 'Image provider', 'supplier' => 'Supplier', 'connection' => 'API', 'version' => 2, 'remove_image' => true])->assertOk()->assertJsonPath('data.logo_url', null);
        Storage::disk('local')->assertMissing($after);
        $this->get('/api/v1/catalog/providers/'.$provider['id'].'/image')->assertNotFound();
    }

    public function test_unauthenticated_catalog_returns_401(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/catalog/products')->assertUnauthorized();
    }

    public function test_provider_creation_persists_original_fields_and_audit_without_internal_fields(): void
    {
        $this->admin();
        $this->postJson('/api/v1/catalog/providers', ['name' => 'شركة الاختبار', 'supplier' => 'المجهز', 'connection' => 'API'])->assertCreated()->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.image_path');
        $this->assertDatabaseHas('catalog_providers', ['name' => 'شركة الاختبار', 'supplier' => 'المجهز', 'connection' => 'API', 'status' => 'active']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'providers.create']);
    }

    public function test_category_creation_persists_all_original_settings_and_exact_decimal_values(): void
    {
        $this->admin();
        $input = $this->productInput();
        $response = $this->postJson('/api/v1/catalog/products', $input)->assertCreated()->assertJsonPath('data.face_value', '5000.00')->assertJsonPath('data.receipt_width', 80)->assertJsonPath('data.import_codes.0', 'EVS-E5K')->assertJsonPath('data.allowed_cities.0', 'بغداد')->assertJsonMissingPath('data.image_path');
        $product = CatalogProduct::findOrFail($response->json('data.id'));
        $this->assertSame('4500.00', $product->minimum_price);
        $this->assertNull($product->daily_amount);
        $this->assertSame($input['field_policy'], $product->field_policy);
        $this->assertDatabaseHas('audit_logs', ['action' => 'products.create']);
    }

    public static function invalidFields(): array
    {
        return [['daily_quantity', 0], ['daily_quantity', 1.5], ['daily_limit_type', 'both'], ['face_value', '1.001'], ['currency', 'EUR'], ['receipt_width', 100], ['allowed_cities', ['Unknown'], 'allowed_cities.0'], ['field_policy', ['pin' => 'unused', 'expiry' => 'required', 'serial' => 'unused', 'cvc' => 'unused', 'reference' => 'unused'], 'field_policy.pin'], ['extra_fields', [['key' => 'constructor', 'label' => 'Unsafe', 'required' => true]], 'extra_fields.0.key'], ['import_codes', ['same', 'SAME']]];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_category_value_returns_422_without_persisting(string $field, mixed $value, ?string $errorField = null): void
    {
        $this->admin();
        $input = $this->productInput();
        $input[$field] = $value;
        $this->postJson('/api/v1/catalog/products', $input)->assertUnprocessable()->assertJsonValidationErrors($errorField ?? $field);
        $this->assertDatabaseCount('catalog_products', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'products.create']);
    }

    public function test_amount_limit_clears_quantity_and_requires_exact_positive_amount(): void
    {
        $this->admin();
        $input = $this->productInput();
        $input['daily_limit_type'] = 'amount';
        $input['daily_quantity'] = null;
        $input['daily_amount'] = '12000.25';
        $this->postJson('/api/v1/catalog/products', $input)->assertCreated()->assertJsonPath('data.daily_quantity', null)->assertJsonPath('data.daily_amount', '12000.25');
    }

    public function test_both_daily_limits_and_unknown_mass_assignment_are_rejected(): void
    {
        $this->admin();
        $input = $this->productInput();
        $input['daily_amount'] = '12.00';
        $input['image_path'] = '../../private';
        $this->postJson('/api/v1/catalog/products', $input)->assertUnprocessable()->assertJsonValidationErrors(['daily_limit_type', 'payload']);
        $this->assertDatabaseCount('catalog_products', 0);
    }

    public function test_stale_update_and_duplicate_name_leave_existing_records_unchanged(): void
    {
        $this->admin();
        $company = CatalogProvider::factory()->create();
        $this->patchJson('/api/v1/catalog/providers/'.$company->id, ['name' => 'Stale', 'supplier' => 'Recorded', 'connection' => 'ملفات', 'version' => 99])->assertConflict();
        $this->assertSame(1, $company->fresh()->version);
        $this->assertNotSame('Stale', $company->fresh()->name);
        $this->postJson('/api/v1/catalog/providers', ['name' => $company->name, 'supplier' => 'Recorded', 'connection' => 'ملفات'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('catalog_providers', 1);
    }

    public function test_status_requires_current_version_and_updates_audit(): void
    {
        $this->admin();
        $product = CatalogProduct::factory()->create();
        $this->patchJson('/api/v1/catalog/products/'.$product->id.'/status', ['version' => 1, 'status' => 'disabled'])->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'disabled');
        $this->assertDatabaseHas('catalog_products', ['id' => $product->id, 'status' => 'disabled', 'version' => 2]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'products.toggle']);
    }

    public function test_reorder_moves_adjacent_category_even_with_equal_order_and_detects_stale_version(): void
    {
        $this->admin();
        $first = CatalogProduct::factory()->create(['display_order' => 1]);
        $second = CatalogProduct::factory()->create(['display_order' => 1]);
        $this->patchJson('/api/v1/catalog/products/'.$second->id.'/move', ['version' => 1, 'direction' => 'up'])->assertOk();
        $this->assertSame([$second->id, $first->id], CatalogProduct::orderBy('display_order')->orderBy('id')->pluck('id')->all());
        $this->patchJson('/api/v1/catalog/products/'.$first->id.'/move', ['version' => 1, 'direction' => 'up'])->assertConflict();
    }

    public function test_multipart_creation_stores_private_image_and_downloads_without_storage_path(): void
    {
        Storage::fake('local');
        $this->admin();
        $input = $this->productInput();
        $response = $this->post('/api/v1/catalog/products', ['payload' => json_encode($input), 'image' => $this->image()], ['Accept' => 'application/json'])->assertCreated()->assertJsonMissingPath('data.image_path');
        $product = CatalogProduct::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($product->image_path);
        $this->get('/api/v1/catalog/products/'.$product->id.'/image')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_disguised_svg_is_rejected_without_saving_image(): void
    {
        Storage::fake('local');
        $this->admin();
        $this->post('/api/v1/catalog/providers', ['payload' => json_encode(['name' => 'Unsafe', 'supplier' => 'Recorded', 'connection' => 'ملفات']), 'image' => UploadedFile::fake()->createWithContent('fake.png', '<svg><script>alert(1)</script></svg>')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('catalog_providers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_audit_failure_rolls_back_record_and_compensates_new_private_image(): void
    {
        Storage::fake('local');
        $this->admin();
        $input = $this->productInput();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Intentional audit failure'));
        $this->post('/api/v1/catalog/products', ['payload' => json_encode($input), 'image' => $this->image()], ['Accept' => 'application/json'])->assertInternalServerError();
        $this->assertDatabaseCount('catalog_products', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(1, (int) DB::table('catalog_meta')->value('version'));
    }

    public function test_provider_filters_pagination_and_unsafe_sort_key_do_not_expand_query(): void
    {
        $this->admin();
        CatalogProvider::factory()->count(2)->create(['supplier' => 'Selected']);
        CatalogProvider::factory()->create(['supplier' => 'Other']);
        $this->getJson('/api/v1/catalog/providers?supplier=Selected&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/catalog/providers?sort=name%3BDELETE')->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->assertDatabaseCount('catalog_providers', 3);
    }
}
