<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\Sales\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class PrivacyControllerPayloadTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $fixture = $this->supportFixture();
        $fixture['system']->update(['name' => 'PRIVATE_SYSTEM_LABEL', 'owner_name' => 'PRIVATE_OWNER_LABEL']);
        $fixture['admin']->update(['name' => 'PRIVATE_OWNER_LABEL', 'email' => 'private-owner@example.test']);
        $fixture['employee'] = $this->supportEmployee($fixture['system'], $fixture['system'], ['staff.view', 'digital.view', 'digital.export', 'integrations.view', 'topup.view']);
        $fixture['employee']->update(['name' => 'Visible employee']);

        return $fixture;
    }

    public function test_employee_session_and_permission_catalog_keep_the_central_account_without_owner_identity(): void
    {
        $fixture = $this->fixture();
        $this->asPortalUser($fixture['employee']);
        $response = $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.name', 'Visible employee')
            ->assertJsonPath('data.account.id', $fixture['system']->id)
            ->assertJsonPath('data.account.name', 'الحساب المركزي')
            ->assertJsonPath('data.account.owner_name', null)
            ->assertJsonPath('data.capabilities.management_account.name', 'الحساب المركزي');
        $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
        $this->assertStringNotContainsString('private-owner@example.test', $response->getContent());

        $this->getJson('/api/v1/permission-catalog')->assertOk()
            ->assertJsonPath('meta.account_id', $fixture['system']->id)
            ->assertJsonPath('meta.account_name', 'الحساب المركزي');
        $this->getJson('/api/v1/digital/options?compact=1')->assertOk()->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.id', $fixture['system']->id)
            ->assertJsonPath('data.accounts.0.name', 'الحساب المركزي');

        $this->asPortalUser($fixture['agent']);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.account.parent_name', 'الحساب المركزي')
            ->assertJsonPath('data.account.owner_name', $fixture['agent']->name);
    }

    public function test_central_connection_and_category_labels_preserve_financial_values_and_records(): void
    {
        $fixture = $this->fixture();
        $connection = DigitalConnection::factory()->create(['account_id' => $fixture['system']->id, 'provider' => 'topup', 'provider_id' => null, 'company_balance_minor' => 1234567]);
        $snapshot = CatalogSnapshot::factory()->create(['connection_id' => $connection->id, 'actor_id' => $fixture['admin']->id]);
        $category = TopupCategory::create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $snapshot->id, 'name' => 'Visible category', 'type' => 'topup', 'remote_id' => 'private-test-category', 'cost_minor' => 430075, 'retail_minor' => 500025, 'active' => true, 'version' => 1]);
        $this->asPortalUser($fixture['employee']);

        $response = $this->getJson('/api/v1/digital/connections')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $connection->id)
            ->assertJsonPath('data.0.account_id', $fixture['system']->id)
            ->assertJsonPath('data.0.account_name', 'الحساب المركزي')
            ->assertJsonPath('data.0.company_balance', '12345.67');
        $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
        $this->getJson('/api/v1/topup/categories')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.main_account_name', 'الحساب المركزي')
            ->assertJsonPath('data.0.price', '4300.75');
        $this->assertDatabaseHas('digital_connections', ['id' => $connection->id, 'company_balance_minor' => 1234567]);
        $this->assertDatabaseHas('topup_categories', ['id' => $category->id, 'cost_minor' => 430075, 'retail_minor' => 500025]);
        Http::assertNothingSent();
    }

    public function test_order_search_and_csv_keep_owner_authored_orders_with_generic_creator_identity(): void
    {
        $fixture = $this->fixture();
        $product = CatalogProduct::factory()->create(['name' => 'Visible product']);
        $connection = DigitalConnection::factory()->create(['account_id' => $fixture['main']->id]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id]);
        $order = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $connection->id, 'main_account_id' => $fixture['main']->id, 'account_id' => $fixture['pos']->id, 'creator_id' => $fixture['admin']->id, 'product_id' => $product->id]);
        $this->asPortalUser($fixture['employee']);

        $this->getJson('/api/v1/digital/orders?q=PRIVATE_OWNER_LABEL')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/digital/orders?q=Visible%20product')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.creator_id', null)
            ->assertJsonPath('data.0.creator_name', 'الخدمة')
            ->assertJsonPath('data.0.retail', '5000.00');
        $csv = $this->get('/api/v1/digital/orders/export')->assertOk()->streamedContent();
        $this->assertStringContainsString($order->request_id, $csv);
        $this->assertStringContainsString('الخدمة', $csv);
        $this->assertStringContainsString('5000.00', $csv);
        $this->assertStringNotContainsString('PRIVATE_', $csv);
        $this->assertDatabaseHas('digital_orders', ['id' => $order->id, 'creator_id' => $fixture['admin']->id, 'quoted_retail_minor' => 500000]);
    }

    public function test_searched_pos_history_keeps_both_sources_when_creator_is_private(): void
    {
        $fixture = $this->fixture();
        $product = CatalogProduct::factory()->create(['name' => 'Visible history product']);
        $connection = DigitalConnection::factory()->create(['account_id' => $fixture['main']->id]);
        $offer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id]);
        $sale = Sale::factory()->create(['main_account_id' => $fixture['main']->id, 'account_id' => $fixture['pos']->id, 'creator_id' => $fixture['admin']->id, 'product_id' => $product->id]);
        $order = DigitalOrder::factory()->create(['offer_id' => $offer->id, 'connection_id' => $connection->id, 'main_account_id' => $fixture['main']->id, 'account_id' => $fixture['pos']->id, 'creator_id' => $fixture['admin']->id, 'product_id' => $product->id]);
        $this->asPortalUser($fixture['posUser']);

        $this->getJson('/api/v1/digital/history?q=PRIVATE_OWNER_LABEL')->assertOk()->assertJsonCount(0, 'data');
        $response = $this->getJson('/api/v1/digital/history?q=Visible%20history%20product')->assertOk()->assertJsonCount(2, 'data');
        $rows = collect($response->json('data'))->keyBy('kind');
        $this->assertSame($sale->id, $rows['stock']['record']['id']);
        $this->assertSame($order->id, $rows['digital']['record']['id']);
        foreach ($rows as $row) {
            $this->assertNull($row['record']['creator_id']);
            $this->assertSame('الخدمة', $row['record']['creator_name']);
        }
        $this->assertStringNotContainsString('PRIVATE_', $response->getContent());
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'creator_id' => $fixture['admin']->id]);
        $this->assertDatabaseHas('digital_orders', ['id' => $order->id, 'creator_id' => $fixture['admin']->id]);

        $this->getJson('/api/v1/digital/history?pagination=cursor&per_page=10&q=Visible%20history%20product')->assertOk()->assertJsonCount(2, 'data');
    }
}
