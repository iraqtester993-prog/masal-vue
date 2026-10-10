<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class DashboardDigitalReadTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_topup_summary_totals_all_visible_balances_and_preserves_unknown_or_zero_balances(): void
    {
        $f = $this->digitalFixture();
        $connections = [];
        for ($i = 0; $i < 6; $i++) {
            $main = $this->account(AccountType::MainAgent, $f['system']);
            $connections[] = DigitalConnection::factory()->create(['account_id' => $main->id, 'provider' => 'topup', 'company_balance_minor' => 100001]);
        }
        $this->asPortalUser($f['admin']);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame('9882543.27', $card['remaining']);
        $this->assertCount(7, $card['balanceRows']);
        $this->assertSame(7, $card['balance_rows_total']);
        $connections[0]->update(['company_balance_minor' => null]);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertNull($card['remaining']);
        $this->assertNull(collect($card['balanceRows'])->firstWhere('agent', $connections[0]->account_id)['remaining']);
        $connections[0]->update(['company_balance_minor' => 0]);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame('9881543.26', $card['remaining']);
        $this->assertSame('0.00', collect($card['balanceRows'])->firstWhere('agent', $connections[0]->account_id)['remaining']);
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_recent_operations_are_limited_scoped_and_hide_provider_and_receipt_secrets(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'rabiaa', ['created_at' => '2026-10-08 00:00:00']);
        for ($i = 0; $i < 34; $i++) {
            $this->completed($f, 'topup', ['created_at' => now()->subMinutes($i)]);
        }
        $foreign = $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonCount(30, 'data.recent_operations');
        $rows = collect($response->json('data.recent_operations'));
        $this->assertFalse($rows->contains('key', 'digital:'.$foreign->id));
        $this->assertSame('topup', $rows->first()['kind']);
        $this->assertSame('5000.02', $rows->first()['amount']);
        $this->assertSame(['provider' => 'topup', 'tab' => 'log'], $rows->first()['parameters']);
        $this->assertStringNotContainsString('PRIVATE_DASHBOARD_', $response->getContent());
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    private function digitalFixture(): array
    {
        $f = $this->supportFixture();
        $product = CatalogProduct::factory()->create();
        $connections = $offers = [];
        foreach (['rabiaa', 'topup'] as $provider) {
            $connections[$provider] = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider_id' => $product->provider_id, 'provider' => $provider, 'credential' => 'PRIVATE_DASHBOARD_CREDENTIAL', 'company_balance_minor' => $provider === 'topup' ? 987654321 : null, 'balance_updated_at' => now()]);
            $offers[$provider] = DigitalOffer::factory()->create(['connection_id' => $connections[$provider]->id, 'product_id' => $product->id]);
        }
        Http::preventStrayRequests();

        return $f + compact('product', 'connections', 'offers');
    }

    private function order(array $f, string $provider = 'rabiaa', array $changes = []): DigitalOrder
    {
        return DigitalOrder::factory()->create(array_replace([
            'connection_id' => $f['connections'][$provider]->id, 'offer_id' => $f['offers'][$provider]->id,
            'product_id' => $f['product']->id, 'main_account_id' => $f['main']->id,
            'account_id' => $f['pos']->id, 'creator_id' => $f['posUser']->id, 'provider' => $provider,
            'quoted_cost_minor' => 430001, 'quoted_retail_minor' => 500002,
            'subscriber' => ['card' => 'PRIVATE_DASHBOARD_SUBSCRIBER'],
            'provider_hold' => ['secret' => 'PRIVATE_DASHBOARD_HOLD'],
        ], $changes));
    }

    private function completed(array $f, string $provider = 'rabiaa', array $changes = []): DigitalOrder
    {
        return $this->order($f, $provider, array_replace([
            'status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => 430001,
            'actual_retail_minor' => 500002, 'cost_basis' => 'provider_response',
            'company_transaction_id' => fake()->uuid(), 'receipt_ref' => fake()->uuid(),
            'receipt' => ['pin' => 'PRIVATE_DASHBOARD_PIN'], 'provider_evidence' => ['secret' => 'PRIVATE_DASHBOARD_EVIDENCE'],
            'resolved_at' => now(),
        ], $changes));
    }

    public function test_fourth_open_amount_card_totals_only_scoped_successful_sales_without_balance_or_local_wallet_debit(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'rabiaa', ['created_at' => '2025-01-01 00:00:00']);
        $this->completed($f, 'rabiaa', ['quoted_retail_minor' => 123457, 'quoted_cost_minor' => 100001, 'actual_retail_minor' => 123457, 'actual_cost_minor' => 100001]);
        $this->order($f);
        $this->order($f, 'rabiaa', ['status' => 'review', 'actual_cost_minor' => 430001, 'actual_retail_minor' => 500002, 'cost_basis' => 'provider_response', 'company_transaction_id' => fake()->uuid()]);
        $this->completed($f, 'rabiaa', ['status' => 'refunded', 'refunded_at' => now()]);
        $this->completed($f, 'rabiaa', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $this->asPortalUser($f['agent']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.availability.digital_services', true);
        $card = collect($response->json('data.cards'))->firstWhere('key', 'digital:rabiaa');
        $this->assertSame('6234.59', $card['value']);
        $this->assertSame('مبلغ المبيع', $card['note']);
        $this->assertSame(['quantity' => 2, 'pending' => 2, 'refunds' => 1], $card['metrics']);
        $this->assertSame(5, $card['rows_total']);
        $this->assertArrayNotHasKey('balanceRows', $card);
        $this->assertArrayNotHasKey('remaining', $card);
        $this->assertSame(['provider' => 'rabiaa', 'tab' => 'log'], $card['parameters']);
        foreach (['PRIVATE_DASHBOARD_', 'credential', 'subscriber', 'receipt_ref', 'provider_hold', 'company_transaction_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertNull($f['connections']['rabiaa']->fresh()->company_balance_minor);
    }

    public function test_topup_unverified_catalog_cost_stays_unknown_and_known_company_balance_is_not_derived_from_sales(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'topup');
        $this->asPortalUser($f['agent']);
        $cards = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'));
        $card = $cards->firstWhere('key', 'digital:topup');
        $this->assertSame('4300.01', $card['value']);
        $this->assertSame('4300.01', $card['balanceRows'][0]['spent']);
        $this->assertSame('9876543.21', $card['balanceRows'][0]['remaining']);
        $this->assertSame($f['main']->id, $card['balanceRows'][0]['agent']);
        $this->completed($f, 'topup', ['cost_basis' => 'catalog_snapshot']);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertNull($card['value']);
        $this->assertNull($card['balanceRows'][0]['spent']);
        $this->assertSame('9876543.21', $card['balanceRows'][0]['remaining']);
        $this->assertSame(['complete' => false, 'unknown_count' => 1, 'catalog_count' => 1], $card['cost_availability']);
        $this->assertStringContainsString('ليست تسوية فعلية', $card['explanation']);
        $this->assertTrue($card['available']);
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertSame(987654321, $f['connections']['topup']->fresh()->company_balance_minor);
    }

    public function test_pos_hides_all_upstream_cost_and_balances_even_with_cost_grant_and_rejects_other_currencies(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'topup');
        $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $this->supportGrant($f['posUser'], 'data.cost');
        $this->supportGrant($f['posUser'], 'data.profit');
        $this->asPortalUser($f['posUser']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $card = collect($response->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame('5000.02', $card['value']);
        $this->assertSame(1, $card['rows_total']);
        $this->assertSame('المبيعات الناجحة', $card['note']);
        $this->assertArrayNotHasKey('cost_availability', $card);
        $this->assertArrayNotHasKey('balanceRows', $card);
        $this->assertStringNotContainsString('4300.01', $response->getContent());
        $this->assertStringNotContainsString('9876543.21', $response->getContent());
        $this->getJson('/api/v1/dashboard/summary?currency=USD')->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->supportGrant($f['posUser'], 'digital.view', false);
        $this->asPortalUser($f['posUser']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonPath('data.availability.digital_services', false);
        $this->assertEmpty(collect($response->json('data.cards'))->filter(fn ($row) => str_starts_with($row['key'], 'digital:')));
    }

    public function test_system_employee_with_pos_only_scope_cannot_see_main_company_balance_or_foreign_sales(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'topup');
        $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $employee = $this->supportEmployee($f['system'], $f['pos'], ['account.view', 'dashboard.view', 'digital.view', 'data.cost']);
        $this->asPortalUser($employee);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame('4300.01', $card['value']);
        $this->assertSame(1, $card['rows_total']);
        $this->assertSame([], $card['balanceRows']);
        $this->assertSame(0, $card['balance_rows_total']);
        $this->assertDatabaseCount('finance_transactions', 0);
    }
}
