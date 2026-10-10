<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PriceParityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    public function test_template_and_import_permissions_are_independent_from_view_and_manual_proposals(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $user = $this->userFor($main);
        $product = CatalogProduct::factory()->create(['minimum_price' => '100.00']);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        foreach (['prices.template', 'prices.import'] as $permission) {
            DB::table('membership_permissions')->insert(['membership_id' => $user->membership->id, 'permission_id' => DB::table('permissions')->where('name', $permission)->value('id'), 'allowed' => false]);
        }
        $this->asPortalUser($user);
        $this->getJson('/api/v1/finance/prices?account_id='.$main->id)->assertOk()->assertJsonPath('data.0.price_updated_at', null)->assertJsonStructure(['data' => [['face_value']]]);
        $this->getJson('/api/v1/finance/price-template?account_id='.$main->id)->assertForbidden();
        $payload = ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '120.00', 'expected_price' => null]], 'idempotency_key' => 'parity-price-import', 'source' => 'import'];
        $this->postJson('/api/v1/finance/price-requests', $payload)->assertForbidden();
        $payload['source'] = 'manual';
        $this->postJson('/api/v1/finance/price-requests', $payload)->assertCreated();
        $this->getJson('/api/v1/finance/prices?account_id='.$main->id)->assertOk()->assertJsonPath('data.0.price', '120.00');
        DB::table('membership_permissions')->where('membership_id', $user->membership->id)->delete();
        $this->asPortalUser($user);
        $this->getJson('/api/v1/finance/price-template?account_id='.$main->id)->assertOk();
        $payload['source'] = 'import';
        $payload['idempotency_key'] = 'parity-price-import-allowed';
        $payload['changes'][0]['expected_price'] = '120.00';
        $payload['changes'][0]['price'] = '125.00';
        $this->postJson('/api/v1/finance/price-requests', $payload)->assertCreated();
    }

    public function test_template_cannot_read_unrelated_agent_prices(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $other = $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/finance/price-template?account_id='.$other->id)->assertNotFound();
    }
}
