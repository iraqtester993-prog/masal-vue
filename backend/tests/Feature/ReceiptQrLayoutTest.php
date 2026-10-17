<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Sales\ReceiptLayout;
use App\Models\User;
use App\Services\Sales\ReceiptDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ReceiptQrLayoutTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $outsider = $this->userFor($foreign);
        $point = $this->userFor($pos);
        $product = CatalogProduct::factory()->create();
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);

        return compact('system', 'main', 'foreign', 'pos', 'admin', 'agent', 'outsider', 'point', 'product');
    }

    private function systemPayload(array $settings = [], int $version = 0, string $key = 'system-qr-layout'): array
    {
        return ['version' => $version, 'idempotency_key' => $key, 'layout' => array_replace(['header' => 'Company header', 'footer' => 'Company footer', 'color' => '#172b4d', 'display_order' => ReceiptDesign::BLOCKS], $settings)];
    }

    private function revoke(User $user, string $permission, bool $allowed = false): void
    {
        DB::table('membership_permissions')->updateOrInsert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id')], ['allowed' => $allowed]);
    }

    public function test_existing_categories_default_to_no_qr_without_guessing_a_carrier_template(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/sales/receipt-layouts/'.$f['product']->id)->assertOk()
            ->assertJsonPath('data.qr_enabled', false)->assertJsonPath('data.recharge_template', '')
            ->assertJsonPath('data.qr_override', false)->assertJsonPath('data.qr_system_enabled', false)
            ->assertJsonPath('data.qr_system_template', '')->assertJsonPath('data.display_order', ReceiptDesign::BLOCKS);
        $this->assertDatabaseCount('sales_receipt_layouts', 0);
    }

    public function test_agent_can_override_or_hide_each_category_and_return_to_system_inheritance(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/sales/receipt-layouts/'.$f['product']->id;
        $this->asPortalUser($f['admin']);
        $this->putJson($url, $this->systemPayload(['qr_enabled' => true, 'recharge_template' => '*101*{pin}#']))->assertOk();
        $this->asPortalUser($f['agent']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', true)->assertJsonPath('data.recharge_template', '*101*{pin}#')->assertJsonPath('data.qr_override', false);
        $personal = ['account_id' => $f['main']->id, 'version' => 0, 'idempotency_key' => 'agent-qr-override', 'layout' => ['qr_enabled' => true, 'recharge_template' => '*222*3*{pin}*1#']];
        $this->putJson($url, $personal)->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', true)->assertJsonPath('data.recharge_template', '*222*3*{pin}*1#')
            ->assertJsonPath('data.qr_override', true)->assertJsonPath('data.qr_system_enabled', true)->assertJsonPath('data.qr_system_template', '*101*{pin}#');
        $this->asPortalUser($f['admin']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.recharge_template', '*222*3*{pin}*1#')->assertJsonPath('data.qr_system_template', '*101*{pin}#');
        $this->asPortalUser($f['agent']);
        $this->putJson($url, array_replace($personal, ['version' => 1, 'idempotency_key' => 'agent-qr-hidden', 'layout' => ['qr_enabled' => false]]))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', false)->assertJsonPath('data.qr_override', true);
        $this->putJson($url, array_replace($personal, ['version' => 2, 'idempotency_key' => 'agent-qr-inherit', 'layout' => ['agent_text' => 'Agent retained', 'qr_enabled' => null, 'recharge_template' => null]]))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', true)->assertJsonPath('data.recharge_template', '*101*{pin}#')
            ->assertJsonPath('data.qr_override', false)->assertJsonPath('data.agent_text', 'Agent retained')->assertJsonPath('data.personal_version', 3);
        $stored = ReceiptLayout::where('scope_key', 'account:'.$f['main']->id.':'.$f['product']->id)->firstOrFail()->layout;
        $this->assertArrayNotHasKey('qr_enabled', $stored);
        $this->assertArrayNotHasKey('recharge_template', $stored);
    }

    public function test_omitted_qr_fields_survive_legacy_saves_and_stale_or_replayed_writes_cannot_overwrite_them(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/sales/receipt-layouts/'.$f['product']->id;
        $this->asPortalUser($f['admin']);
        $payload = $this->systemPayload(['qr_enabled' => true, 'recharge_template' => '*101*{pin}#']);
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.version', 1);
        $this->putJson($url, $this->systemPayload(['qr_enabled' => false], 0, 'system-qr-stale'))->assertConflict();
        $this->putJson($url, $this->systemPayload(['header' => 'Legacy update'], 1, 'system-layout-legacy'))->assertOk()
            ->assertJsonPath('data.layout.qr_enabled', true)->assertJsonPath('data.layout.recharge_template', '*101*{pin}#');
        $this->asPortalUser($f['agent']);
        $personal = ['account_id' => $f['main']->id, 'version' => 0, 'idempotency_key' => 'agent-qr-hidden-initial', 'layout' => ['qr_enabled' => false, 'recharge_template' => '*133*{pin}#']];
        $this->putJson($url, $personal)->assertOk();
        $this->putJson($url, array_replace($personal, ['version' => 1, 'idempotency_key' => 'agent-layout-legacy', 'layout' => ['agent_text' => 'Legacy agent update']]))->assertOk();
        $this->putJson($url, array_replace($personal, ['version' => 1, 'idempotency_key' => 'agent-qr-stale', 'layout' => ['qr_enabled' => true]]))->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', false)->assertJsonPath('data.recharge_template', '*133*{pin}#')->assertJsonPath('data.qr_override', true);
        $this->assertDatabaseCount('sales_receipt_layouts', 2);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_templates_reject_urls_missing_or_repeated_pin_placeholders_and_non_ussd_content(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $invalid = ['https://example.test/{pin}', 'tel:*101*{pin}#', '*101*1234567890123456#', '*101*{pin}*{pin}#', '*101*{PIN}#', '101*{pin}#', '#101*{pin}#', '*101*{pin}', '*101#{pin}#', '*101* {pin}#', "*101*\n{pin}#", '*1234567*{pin}#', '*101*{pin}*abc#', '*'.str_repeat('1*', 40).'{pin}#'];
        foreach ($invalid as $index => $template) {
            $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $this->systemPayload(['qr_enabled' => false, 'recharge_template' => $template], 0, 'invalid-qr-template-'.$index))
                ->assertUnprocessable()->assertJsonValidationErrors('layout.recharge_template');
        }
        $this->assertDatabaseCount('sales_receipt_layouts', 0);
        $this->assertDatabaseCount('finance_operations', 0);
    }

    public function test_enabling_requires_a_valid_effective_template_and_only_personal_flags_can_inherit_null(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/sales/receipt-layouts/'.$f['product']->id;
        $this->asPortalUser($f['admin']);
        $this->putJson($url, $this->systemPayload(['qr_enabled' => true, 'recharge_template' => null]))->assertUnprocessable()->assertJsonValidationErrors('layout.recharge_template');
        $this->putJson($url, $this->systemPayload(['qr_enabled' => null], 0, 'system-null-qr'))->assertUnprocessable()->assertJsonValidationErrors('layout.qr_enabled');
        $this->asPortalUser($f['agent']);
        $personal = ['account_id' => $f['main']->id, 'version' => 0, 'idempotency_key' => 'agent-enable-empty-qr', 'layout' => ['qr_enabled' => true, 'recharge_template' => null]];
        $this->putJson($url, $personal)->assertUnprocessable()->assertJsonValidationErrors('layout.recharge_template');
        $this->asPortalUser($f['admin']);
        $this->putJson($url, $this->systemPayload(['qr_enabled' => false, 'recharge_template' => '*101*{pin}#'], 0, 'system-qr-template-only'))->assertOk();
        $this->asPortalUser($f['agent']);
        $this->putJson($url, array_replace($personal, ['idempotency_key' => 'agent-enable-inherited-template']))->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.qr_enabled', true)->assertJsonPath('data.recharge_template', '*101*{pin}#')->assertJsonPath('data.qr_override', true)->assertJsonPath('data.qr_system_enabled', false);
    }

    public function test_qr_edits_keep_existing_product_permissions_and_main_agent_account_scope(): void
    {
        $f = $this->fixture();
        $url = '/api/v1/sales/receipt-layouts/'.$f['product']->id;
        $payload = $this->systemPayload(['qr_enabled' => true, 'recharge_template' => '*101*{pin}#']);
        $personal = ['account_id' => $f['main']->id, 'version' => 0, 'idempotency_key' => 'scoped-agent-qr', 'layout' => ['qr_enabled' => true, 'recharge_template' => '*101*{pin}#']];
        $this->asPortalUser($f['agent']);
        $this->putJson($url, $payload)->assertForbidden();
        $this->putJson($url, array_replace($personal, ['account_id' => $f['foreign']->id]))->assertNotFound();
        $this->asPortalUser($f['outsider']);
        $this->putJson($url, $personal)->assertNotFound();
        $this->asPortalUser($f['point']);
        $this->putJson($url, $personal)->assertForbidden();
        $this->revoke($f['agent'], 'branding.edit');
        $this->asPortalUser($f['agent']);
        $this->putJson($url, $personal)->assertForbidden();
        $this->revoke($f['admin'], 'branding.receipt');
        $this->asPortalUser($f['admin']);
        $this->putJson($url, $payload)->assertForbidden();
        $this->revoke($f['admin'], 'branding.receipt', true);
        $this->revoke($f['admin'], 'products.edit');
        $this->asPortalUser($f['admin']);
        $this->putJson($url, $payload)->assertForbidden();
        $this->assertDatabaseCount('sales_receipt_layouts', 0);
    }

    public function test_invalid_stored_template_fails_closed_without_replacing_valid_system_defaults(): void
    {
        $f = $this->fixture();
        ReceiptLayout::factory()->create(['product_id' => $f['product']->id, 'scope_key' => 'system:'.$f['product']->id, 'layout' => ['qr_enabled' => true, 'recharge_template' => '*101*{pin}#']]);
        ReceiptLayout::factory()->create(['product_id' => $f['product']->id, 'account_id' => $f['main']->id, 'scope_key' => 'account:'.$f['main']->id.':'.$f['product']->id, 'layout' => ['qr_enabled' => true, 'recharge_template' => 'https://example.test/{pin}']]);
        $this->asPortalUser($f['agent']);
        $this->getJson('/api/v1/sales/receipt-layouts/'.$f['product']->id)->assertOk()->assertJsonPath('data.qr_enabled', false)->assertJsonPath('data.recharge_template', '')
            ->assertJsonPath('data.qr_override', true)->assertJsonPath('data.qr_system_enabled', true)->assertJsonPath('data.qr_system_template', '*101*{pin}#');
    }
}
