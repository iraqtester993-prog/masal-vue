<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CatalogExportTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_baghdad_business_day_filters_match_list_and_export_at_utc_midnight(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        $included = CatalogProvider::factory()->create(['created_at' => '2026-10-05 21:00:00']);
        CatalogProvider::factory()->create(['created_at' => '2026-10-05 20:59:59']);
        CatalogProvider::factory()->create(['created_at' => '2026-10-06 21:00:00']);
        $this->getJson('/api/v1/catalog/providers?from=2026-10-06&to=2026-10-06')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $included->id);
        $result = $this->getJson('/api/v1/catalog/providers/export?from=2026-10-06&to=2026-10-06')->assertOk()->assertJsonPath('data.count', 1);
        $this->assertStringContainsString($included->name, $result->json('data.csv'));
    }

    public function test_export_applies_filters_preserves_arabic_and_neutralizes_spreadsheet_formulas(): void
    {
        $this->asPortalUser($this->userFor($this->account(AccountType::System)));
        CatalogProvider::factory()->create(['name' => '=HYPERLINK("bad")', 'supplier' => 'مجهز محدد']);
        CatalogProvider::factory()->create(['name' => 'خارج الفلتر', 'supplier' => 'آخر']);
        $result = $this->getJson('/api/v1/catalog/providers/export?supplier='.urlencode('مجهز محدد'))->assertOk()->assertJsonPath('data.count', 1)->assertHeaderContains('Cache-Control', 'no-store');
        $csv = $result->json('data.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('مجهز محدد', $csv);
        $this->assertStringNotContainsString('خارج الفلتر', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'providers.export']);
    }

    public function test_agent_export_includes_only_explicitly_allowed_products_and_no_private_paths(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $allowed = CatalogProduct::factory()->create(['name' => 'مسموح', 'image_path' => 'catalog/private/test.png']);
        CatalogProduct::factory()->create(['name' => 'ممنوع']);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $allowed->id]);
        $this->asPortalUser($this->userFor($main));
        $result = $this->getJson('/api/v1/catalog/products/export')->assertOk()->assertJsonPath('data.count', 1);
        $this->assertStringContainsString('مسموح', $result->json('data.csv'));
        $this->assertStringNotContainsString('ممنوع', $result->json('data.csv'));
        $this->assertStringNotContainsString('catalog/private', $result->json('data.csv'));
        $this->getJson('/api/v1/catalog/providers/export')->assertForbidden();
    }

    public function test_revoked_export_permission_cannot_be_bypassed_with_direct_url(): void
    {
        $user = $this->userFor($this->account(AccountType::System));
        DB::table('membership_permissions')->insert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'products.export')->value('id'), 'allowed' => false]);
        $this->asPortalUser($user);
        $this->getJson('/api/v1/catalog/products/export')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'products.export']);
    }

    public function test_column_preferences_are_private_to_each_user_and_cannot_hide_required_columns(): void
    {
        $system = $this->account(AccountType::System);
        $one = $this->userFor($system);
        $two = $this->userFor($system);
        $this->asPortalUser($one);
        $this->putJson('/api/v1/catalog/preferences', ['hidden_columns' => ['image', 'receiptFooter']])->assertOk()->assertJsonPath('data.hidden_columns.0', 'image');
        $this->putJson('/api/v1/catalog/preferences', ['hidden_columns' => ['name', 'actions']])->assertUnprocessable();
        $this->asPortalUser($two);
        $this->getJson('/api/v1/catalog/preferences')->assertOk()->assertJsonCount(0, 'data.hidden_columns');
        $this->asPortalUser($one);
        $this->getJson('/api/v1/catalog/preferences')->assertOk()->assertJsonCount(2, 'data.hidden_columns');
    }
}
