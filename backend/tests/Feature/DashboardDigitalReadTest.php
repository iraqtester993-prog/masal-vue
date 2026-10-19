<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupGrant;
use App\Models\Sales\Sale;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Finance\Ledger;
use App\Services\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class DashboardDigitalReadTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    public function test_derived_totals_follow_resolution_refund_and_transaction_rollback(): void
    {
        $f = $this->digitalFixture();
        $order = $this->order($f, 'topup', ['status' => 'pending', 'actual_cost_minor' => null, 'actual_retail_minor' => null, 'cost_basis' => null]);
        $resolved = ['actual_cost_minor' => 430001, 'actual_retail_minor' => 500002, 'status' => 'succeeded', 'cost_basis' => 'provider_response', 'reservation_active' => false, 'company_transaction_id' => fake()->uuid(), 'receipt_ref' => fake()->uuid(), 'resolved_at' => now()];
        $this->assertSame(1, (int) DB::table('digital_order_totals')->sum('order_count'));
        DB::beginTransaction();
        try {
            DB::table('digital_orders')->where('id', $order->id)->update($resolved);
            $this->assertSame(430001, (int) DB::table('digital_order_totals')->sum('cost_minor'));
            $this->assertSame(1, (int) DB::table('digital_order_totals')->where('status', 'succeeded')->sum('order_count'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, (int) DB::table('digital_order_totals')->sum('cost_minor'));
        $this->assertSame(1, (int) DB::table('digital_order_totals')->where('status', 'pending')->sum('order_count'));
        DB::table('digital_orders')->where('id', $order->id)->update($resolved);
        DB::table('digital_orders')->where('id', $order->id)->update(['status' => 'refunded', 'refunded_at' => now()]);
        $this->assertSame(430001, (int) DB::table('digital_order_totals')->sum('cost_minor'));
        $this->assertSame(500002, (int) DB::table('digital_order_totals')->sum('retail_minor'));
        $this->assertSame(1, (int) DB::table('digital_order_totals')->where('status', 'refunded')->sum('order_count'));
        $this->assertSame(1, (int) DB::table('digital_order_totals')->sum('order_count'));
        $this->assertSame(0, (int) DB::table('digital_order_totals')->sum('unknown_cost_count'));
    }

    public function test_order_page_keys_keep_foreign_rows_out_and_preserve_last_page_totals(): void
    {
        $f = $this->digitalFixture();
        $ownIds = [];
        for ($i = 0; $i < 35; $i++) {
            $ownIds[] = $this->completed($f, 'topup')->id;
            $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        }
        $this->asPortalUser($f['posUser']);
        $last = $this->getJson('/api/v1/digital/orders?per_page=10&page=4')->assertOk()
            ->assertJsonPath('meta.total', 35)->assertJsonPath('meta.last_page', 4)->assertJsonPath('meta.current_page', 4)->assertJsonCount(5, 'data');
        $this->assertSame(array_reverse(array_slice($ownIds, 0, 5)), array_column($last->json('data'), 'id'));
        $middle = $this->getJson('/api/v1/digital/orders?per_page=10&page=3')->assertOk()->assertJsonCount(10, 'data');
        $this->assertSame(array_reverse(array_slice($ownIds, 5, 10)), array_column($middle->json('data'), 'id'));
        $this->getJson('/api/v1/digital/orders?per_page=10&page=5')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 35);
        $this->getJson('/api/v1/digital/orders?per_page=10&status=failed')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->asPortalUser($f['admin']);
        foreach (['account_id' => $f['pos']->id, 'main_account_id' => $f['main']->id] as $field => $id) {
            $filtered = $this->getJson('/api/v1/digital/orders?per_page=10&page=4&'.$field.'='.$id)->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 35);
            $this->assertSame(array_reverse(array_slice($ownIds, 0, 5)), array_column($filtered->json('data'), 'id'));
        }
    }

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

    public function test_full_point_card_uses_bounded_visible_ids_for_candidates_and_keeps_latest_five_scoped_rows(): void
    {
        $f = $this->digitalFixture();
        $own = [];
        for ($i = 0; $i < 8; $i++) {
            $product = CatalogProduct::factory()->create(['name' => 'Latest own '.$i]);
            $this->completed($f, 'topup', ['product_id' => $product->id, 'created_at' => now()->subMinutes($i)]);
            $own[] = $f['pos']->name.' · '.$f['posUser']->name.' · '.$product->name;
            $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'digital_orders') && str_contains($query->sql, 'limit 5')) {
                $queries[] = $query->sql;
            }
        });
        $this->asPortalUser($f['posUser']);
        $card = collect($this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame(8, $card['rows_total']);
        $this->assertCount(5, $card['rows']);
        $this->assertSame(array_slice($own, 0, 5), array_column($card['rows'], 'name'));
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('select "accounts"."id"', $query);
            $this->assertStringNotContainsString('select `accounts`.`id`', $query);
        }
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_compact_cards_preserve_totals_and_full_details_are_bounded(): void
    {
        $f = $this->digitalFixture();
        for ($i = 0; $i < 102; $i++) {
            DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'topup', 'company_balance_minor' => 100001]);
        }
        $this->asPortalUser($f['admin']);
        $full = $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $compact = $this->getJson('/api/v1/dashboard/summary?view=cards')->assertOk();
        $fullCard = collect($full->json('data.cards'))->firstWhere('key', 'digital:topup');
        $card = collect($compact->json('data.cards'))->firstWhere('key', 'digital:topup');
        $this->assertSame($fullCard['remaining'], $card['remaining']);
        $this->assertSame($fullCard['value'], $card['value']);
        $this->assertSame(103, $card['balance_rows_total']);
        $this->assertCount(100, $fullCard['balanceRows']);
        $this->assertSame([], $card['balanceRows']);
        $compact->assertJsonCount(0, 'data.chart')->assertJsonCount(0, 'data.regions')->assertJsonCount(0, 'data.recent_operations');
        $this->assertLessThan(strlen($full->getContent()), strlen($compact->getContent()));
        $this->getJson('/api/v1/dashboard/summary?view=wrong')->assertUnprocessable();
    }

    public function test_all_activity_uses_bounded_cursor_pages_without_skipping_equal_timestamps(): void
    {
        $f = $this->digitalFixture();
        for ($i = 0; $i < 205; $i++) {
            DB::table('audit_logs')->insert(['user_id' => $f['manager']->id, 'account_id' => $f['system']->id, 'subject_account_id' => $f['main']->id, 'portal' => 'admin', 'action' => 'wallets.deposit', 'created_at' => '2026-10-01 10:00:00', 'details' => '{}']);
        }
        $privateId = DB::table('audit_logs')->insertGetId(['user_id' => $f['admin']->id, 'account_id' => $f['system']->id, 'subject_account_id' => $f['main']->id, 'portal' => 'admin', 'action' => 'wallets.deposit', 'created_at' => '2026-10-01 10:00:01', 'details' => '{}']);
        $this->asPortalUser($f['admin']);
        $first = $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(100, 'data');
        $second = $this->getJson('/api/v1/dashboard/activity?limit=all&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonCount(100, 'data');
        $third = $this->getJson('/api/v1/dashboard/activity?limit=all&cursor='.urlencode($second->json('meta.next_cursor')))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.next_cursor', null);
        $keys = array_merge(array_column($first->json('data'), 'key'), array_column($second->json('data'), 'key'), array_column($third->json('data'), 'key'));
        $this->assertCount(205, array_unique($keys));
        $this->assertNotContains('audit:'.$privateId, $keys);
        $this->assertDatabaseHas('audit_logs', ['id' => $privateId, 'user_id' => $f['admin']->id]);
        $this->getJson('/api/v1/dashboard/activity?limit=all&cursor=invalid')->assertUnprocessable();
    }

    private function digitalFixture(): array
    {
        $f = $this->supportFixture();
        $f['manager'] = $this->supportEmployee($f['system'], $f['system'], array_keys(PermissionCatalog::LABELS));
        $product = CatalogProduct::factory()->create();
        $connections = $offers = [];
        foreach (['rabiaa', 'topup'] as $provider) {
            $connections[$provider] = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider_id' => $product->provider_id, 'provider' => $provider, 'credential' => 'PRIVATE_DASHBOARD_CREDENTIAL', 'company_balance_minor' => $provider === 'topup' ? 987654321 : null, 'balance_updated_at' => now()]);
            $offers[$provider] = DigitalOffer::factory()->create(['connection_id' => $connections[$provider]->id, 'product_id' => $product->id]);
        }
        Http::preventStrayRequests();

        return $f + compact('product', 'connections', 'offers');
    }

    private function activityPosting(array $fixture, string $service, int $amount, string $kind = 'deposit', bool $debit = false, ?int $accountId = null): int
    {
        return DB::transaction(function () use ($fixture, $service, $amount, $kind, $debit, $accountId): int {
            $ledger = app(Ledger::class);
            $external = $ledger->wallet($fixture['system']->id, $service, 'IQD', 'external');
            $point = $ledger->wallet($accountId ?? $fixture['pos']->id, $service, 'IQD');

            return $ledger->pair($fixture['manager'], $kind, fake()->uuid(), [], $debit ? $point : $external, $debit ? $external : $point, $amount, 'Financial activity fixture')->id;
        });
    }

    public function test_pos_activity_uses_effective_financial_records_and_deduplicates_purchase_postings(): void
    {
        $f = $this->digitalFixture();
        $this->activityPosting($f, 'cash', 100000);
        $privatePosting = $this->activityPosting(array_replace($f, ['manager' => $f['admin']]), 'cash', 100000);
        $this->activityPosting($f, 'cash', 100000, accountId: $f['foreign']->id);
        $this->activityPosting($f, 'voucher', 100000);
        $posting = $this->activityPosting($f, 'voucher', 10000, 'sale', true);
        $rule = DB::table('catalog_account_rules')->insertGetId(['authority_account_id' => $f['system']->id, 'target_account_id' => $f['main']->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $f['product']->id]);
        $sale = Sale::factory()->create(['main_account_id' => $f['main']->id, 'account_id' => $f['pos']->id, 'creator_id' => $f['posUser']->id, 'product_id' => $f['product']->id, 'transaction_id' => $posting, 'status' => 'Reprinted', 'issued_at' => now()]);
        $order = $this->completed($f, 'rabiaa', ['status' => 'refunded', 'refunded_at' => now()]);
        $this->order($f);
        $this->completed($f, 'topup', ['account_id' => $f['foreign']->id]);
        foreach (['auth.login', 'support.reply', 'sell.reprint'] as $action) {
            DB::table('audit_logs')->insert(['user_id' => $f['posUser']->id, 'account_id' => $f['pos']->id, 'subject_account_id' => $f['pos']->id, 'portal' => 'pos', 'action' => $action, 'created_at' => now(), 'details' => '{"amount":99999}']);
        }
        $this->asPortalUser($f['posUser']);
        $response = $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(5, 'data');
        $rows = collect($response->json('data'));
        $this->assertSame(1, $rows->where('key', 'stock:'.$sale->id)->count());
        $this->assertTrue($rows->contains('key', 'digital:'.$order->id));
        $this->assertTrue($rows->contains('key', 'refund:'.$order->id));
        $this->assertSame(2, $rows->where('kind', 'ledger')->count());
        $this->assertNotContains($f['admin']->name, $rows->pluck('actor_name'));
        $this->assertContains($f['manager']->name, $rows->pluck('actor_name'));
        $this->assertDatabaseHas('finance_transactions', ['id' => $privatePosting, 'actor_id' => $f['admin']->id]);
        $this->assertSame('مبلغ الاسترجاع', $rows->firstWhere('key', 'refund:'.$order->id)['amount_label']);
        $this->assertArrayNotHasKey('direction', $rows->firstWhere('key', 'stock:'.$sale->id));
        $this->assertStringNotContainsString('PRIVATE_DASHBOARD_', $response->getContent());
        $this->assertFalse($rows->contains('kind', 'audit'));

        $this->supportGrant($f['posUser'], 'wallets.view', false);
        $this->supportGrant($f['posUser'], 'sales.view', false);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(2, 'data');
        $employee = $this->supportEmployee($f['pos'], $f['foreign'], ['dashboard.view', 'digital.view', 'wallets.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pos_financial_history_pages_all_equal_timestamp_sources_without_gaps(): void
    {
        $f = $this->digitalFixture();
        $this->freezeTime();
        for ($index = 0; $index < 103; $index++) {
            $this->activityPosting($f, 'cash', 100);
        }
        $order = $this->completed($f, 'rabiaa', ['status' => 'refunded', 'refunded_at' => now()]);
        $this->asPortalUser($f['posUser']);
        $first = $this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(100, 'data');
        $second = $this->getJson('/api/v1/dashboard/activity?limit=all&cursor='.urlencode($first->json('meta.next_cursor')))->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.next_cursor', null);
        $keys = array_merge(array_column($first->json('data'), 'key'), array_column($second->json('data'), 'key'));
        $this->assertCount(105, array_unique($keys));
        $this->assertContains('digital:'.$order->id, $keys);
        $this->assertContains('refund:'.$order->id, $keys);
    }

    public function test_pos_topup_purchase_and_refund_replace_their_posted_wallet_duplicates(): void
    {
        $f = $this->digitalFixture();
        $grant = TopupGrant::factory()->create(['main_account_id' => $f['main']->id, 'from_account_id' => $f['system']->id, 'target_account_id' => $f['main']->id, 'connection_id' => $f['connections']['topup']->id]);
        $order = $this->completed($f, 'topup', ['status' => 'refunded', 'refunded_at' => now(), 'topup_grant_id' => $grant->id]);
        $this->activityPosting($f, 'topup', 1000000);
        $sale = $this->activityPosting($f, 'topup', 500002, 'topup-sale', true);
        $refund = $this->activityPosting($f, 'topup', 500002, 'topup-refund');
        foreach (['sale' => $sale, 'refund' => $refund] as $phase => $posting) {
            DB::table('topup_finance_events')->insert(['transaction_id' => $posting, 'grant_id' => $grant->id, 'connection_id' => $f['connections']['topup']->id, 'order_id' => $order->id, 'phase' => $phase, 'event_key' => fake()->uuid(), 'created_at' => now()]);
        }
        $this->asPortalUser($f['posUser']);
        $rows = collect($this->getJson('/api/v1/dashboard/activity?limit=all')->assertOk()->assertJsonCount(3, 'data')->json('data'));
        $this->assertSame(1, $rows->where('kind', 'ledger')->count());
        $this->assertTrue($rows->contains('key', 'digital:'.$order->id));
        $this->assertTrue($rows->contains('key', 'refund:'.$order->id));
    }

    public function test_account_rollups_stay_small_preserve_scope_and_product_filters_use_detailed_totals(): void
    {
        $f = $this->digitalFixture();
        for ($i = 0; $i < 13; $i++) {
            $product = $i === 0 ? $f['product'] : CatalogProduct::factory()->create();
            $this->completed($f, 'topup', ['product_id' => $product->id]);
        }
        $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $this->assertSame(14, DB::table('digital_order_totals')->count());
        $this->assertSame(2, DB::table('digital_account_totals')->count());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/digital/orders/summary?provider=topup')->assertOk()
            ->assertJsonPath('data.quantity', 13)->assertJsonPath('data.retail', '65000.26');
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'digital_account_totals')));
        $queries = [];
        $this->getJson('/api/v1/digital/orders/summary?product_id='.$f['product']->id)->assertOk()
            ->assertJsonPath('data.quantity', 1)->assertJsonPath('data.retail', '5000.02');
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'digital_order_totals')));
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/orders/summary?provider=topup')->assertOk()->assertJsonPath('data.quantity', 14);
    }

    public function test_account_rollup_backfill_replays_once_and_history_deletion_cannot_remove_its_amounts(): void
    {
        $f = $this->digitalFixture();
        $this->completed($f, 'topup');
        $migration = require database_path('migrations/2026_10_14_002408_create_digital_account_totals.php');
        $migration->down();
        $this->completed($f, 'topup');
        $migration->up();
        $migration->up();
        $this->assertSame(2, (int) DB::table('digital_account_totals')->sum('order_count'));
        $this->assertSame(860002, (int) DB::table('digital_account_totals')->sum('cost_minor'));
        $this->assertSame(1000004, (int) DB::table('digital_account_totals')->sum('retail_minor'));
        $pending = $this->order($f, 'topup');
        $this->assertSame(3, (int) DB::table('digital_account_totals')->sum('order_count'));
        try {
            DB::table('digital_orders')->where('id', $pending->id)->delete();
            $this->fail('Digital history must stay immutable.');
        } catch (QueryException) {
            $this->assertSame(3, (int) DB::table('digital_account_totals')->sum('order_count'));
            $this->assertSame(1, (int) DB::table('digital_account_totals')->where('status', 'pending')->sum('order_count'));
        }
    }

    public function test_sqlite_rebuild_rolls_back_totals_and_order_triggers_when_backfill_fails(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite atomic DDL test.');
        }
        $f = $this->digitalFixture();
        $this->completed($f, 'topup');
        DB::unprepared("CREATE TRIGGER rollup_reject_fixture BEFORE INSERT ON digital_account_totals BEGIN SELECT RAISE(ABORT, 'fixture backfill failure'); END");
        $migration = require database_path('migrations/2026_10_14_002408_create_digital_account_totals.php');
        try {
            $migration->up();
            $this->fail('The fixture must reject the backfill.');
        } catch (QueryException) {
            $this->assertSame(1, (int) DB::table('digital_account_totals')->sum('order_count'));
            $this->assertSame(430001, (int) DB::table('digital_account_totals')->sum('cost_minor'));
        } finally {
            DB::unprepared('DROP TRIGGER rollup_reject_fixture');
        }
        $this->completed($f, 'topup');
        $this->assertSame(2, (int) DB::table('digital_account_totals')->sum('order_count'));
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

    public function test_main_employee_with_one_point_scope_cannot_inherit_main_allocation_balances(): void
    {
        $f = $this->digitalFixture();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['system']->id, 'provider' => 'topup']);
        TopupGrant::factory()->create(['from_account_id' => $f['system']->id, 'target_account_id' => $f['main']->id, 'main_account_id' => $f['main']->id, 'connection_id' => $connection->id, 'balance_minor' => 98712300, 'spent_minor' => 65432100]);
        $order = $this->completed($f, 'topup');
        $foreign = $this->completed($f, 'topup', ['account_id' => $f['foreign']->id, 'main_account_id' => $f['foreign']->id]);
        $employee = $this->supportEmployee($f['main'], $f['pos'], ['account.view', 'dashboard.view', 'digital.view', 'data.cost']);
        $this->asPortalUser($employee);
        foreach (['/api/v1/dashboard/summary', '/api/v1/dashboard/summary?view=cards'] as $path) {
            $card = collect($this->getJson($path)->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
            $this->assertSame('4300.01', $card['value']);
            $this->assertNull($card['remaining']);
            $this->assertSame([], $card['balanceRows']);
            $this->assertSame(0, $card['balance_rows_total']);
        }
        $this->getJson('/api/v1/digital/orders/summary?provider=topup')->assertOk()->assertJsonPath('data.quantity', 1)->assertJsonPath('data.retail', '5000.02');
        $this->getJson('/api/v1/digital/orders/'.$order->id)->assertOk();
        $this->getJson('/api/v1/digital/orders/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/digital/connections?provider=topup')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('balance.known', 0)->assertJsonPath('balance.amount', null);
        $this->getJson('/api/v1/digital/connections?main_account_id='.$f['main']->id)->assertNotFound();
        $dto = app(DigitalConfiguration::class)->dto($connection, $employee);
        foreach (['company_balance', 'balance_updated_at', 'main_account_id', 'allocation_available', 'allocation_spent', 'allocation_held', 'unallocated_balance'] as $field) {
            $this->assertArrayNotHasKey($field, $dto);
        }
        $this->assertSame([], $dto['grants']);
        $this->assertDatabaseCount('finance_transactions', 0);
    }
}
