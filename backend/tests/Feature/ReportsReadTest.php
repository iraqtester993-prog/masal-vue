<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountMembership;
use App\Models\CatalogProduct;
use App\Models\Finance\Invoice;
use App\Models\Finance\PriceRequest;
use App\Models\Notifications\Notice;
use App\Models\OrderSource;
use App\Models\PermissionProfile;
use App\Models\Sales\PrintRule;
use App\Models\Sales\Sale;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Finance\Ledger;
use App\Services\ManagementAuthority;
use App\Services\Reports\ReportDataset;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ReportsReadTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function setupTree(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $pos = $this->account(AccountType::Pos, $main);
        $other = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $seller = $this->userFor($pos);
        $product = CatalogProduct::factory()->create();
        foreach ([$main, $other] as $account) {
            $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $account->id, 'authority_account_id' => $system->id]);
            DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        }

        return compact('system', 'main', 'pos', 'other', 'admin', 'agent', 'seller', 'product');
    }

    public function test_dashboard_batches_agent_counts_and_preserves_baghdad_day_boundaries(): void
    {
        $tree = $this->setupTree();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->sale($tree, ['issued_at' => '2026-10-05 20:59:59']);
        $this->sale($tree, ['issued_at' => '2026-10-05 21:00:00']);
        $this->sale($tree, ['issued_at' => '2026-10-06 06:00:00']);
        $this->sale($tree, ['issued_at' => null, 'status' => 'Reserved']);
        for ($i = 0; $i < 8; $i++) {
            $agent = $this->account(AccountType::MainAgent, $tree['system']);
            $this->account(AccountType::Pos, $agent);
        }
        $this->asPortalUser($tree['admin']);
        DB::enableQueryLog();
        try {
            $response = $this->getJson('/api/v1/dashboard/summary')->assertOk();
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $response->assertJsonCount(7, 'data.chart')->assertJsonCount(10, 'data.regions')->assertJsonCount(3, 'data.recent_operations')->assertJsonPath('data.recent_operations.0.kind', 'stock');
        $chart = collect($response->json('data.chart'))->keyBy('day');
        $this->assertSame('100.01', $chart['2026-10-05']['value']);
        $this->assertSame('200.02', $chart['2026-10-06']['value']);
        $this->assertSame(2, $chart['2026-10-06']['transactions']);
        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'region_scope'))->count());
        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'amount_6'))->count());
        $regions = collect($response->json('data.regions'))->keyBy('id');
        $this->assertSame(1, $regions[$tree['main']->id]['pos_count']);
        $this->assertSame(0, $regions[$tree['other']->id]['pos_count']);
    }

    public function test_summary_counts_only_requested_sections_and_rejects_unknown_sections(): void
    {
        $tree = $this->setupTree();
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/reports/summary?section_ids[]=audit')->assertOk()
            ->assertJsonCount(1, 'data.sections')->assertJsonPath('data.sections.0.id', 'audit');
        $this->getJson('/api/v1/reports/summary?section_ids[]=unknown')->assertNotFound();
        $schemas = collect($this->getJson('/api/v1/reports/options')->assertOk()->json('data.sections'))->keyBy('id');
        $columns = collect($schemas['audit']['columns'])->keyBy('key');
        $this->assertSame('action', $columns['action']['label_type']);
        $this->assertSame('portal', $columns['source']['label_type']);
        $provider = collect($schemas['sales-services']['columns'])->keyBy('key')['provider'];
        $this->assertArrayNotHasKey('label_type', $provider);
        $this->asPortalUser($tree['seller'], 'pos');
        $this->getJson('/api/v1/reports/summary?section_ids[]=audit')->assertNotFound();
    }

    private function sale(array $tree, array $changes = []): Sale
    {
        return Sale::factory()->create(array_replace(['account_id' => $tree['pos']->id, 'main_account_id' => $tree['main']->id, 'creator_id' => $tree['seller']->id, 'product_id' => $tree['product']->id, 'provider_id' => $tree['product']->provider_id, 'issued_at' => '2026-10-06 06:00:00', 'status' => 'Printed', 'total_minor' => 10001, 'retail_total_minor' => 12002, 'credit_minor' => 10001, 'cost_minor' => 8000, 'load_cost_minor' => 9000], $changes));
    }

    public function test_recent_operations_include_management_and_finance_events_without_exposing_private_details(): void
    {
        $tree = $this->setupTree();
        foreach (['account.update', 'wallets.transfer', 'support.reply', 'security.update'] as $index => $action) {
            DB::table('audit_logs')->insert(['user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'subject_account_id' => $tree['pos']->id, 'action' => $action, 'portal' => 'admin', 'created_at' => '2026-10-10 08:00:0'.$index, 'details' => json_encode(['amount' => '6000.00', 'credential' => 'secret-never-visible', 'phone' => 'private-number'])]);
        }
        DB::table('audit_logs')->insert(['user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'subject_account_id' => $tree['other']->id, 'action' => 'wallets.transfer', 'portal' => 'admin', 'created_at' => '2026-10-10 09:00:00']);
        $this->asPortalUser($tree['admin']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonCount(5, 'data.recent_operations');
        $this->assertStringNotContainsString('secret-never-visible', $response->getContent());
        $this->assertStringNotContainsString('private-number', $response->getContent());
        $this->assertSame('wallets.transfer', $response->json('data.recent_operations.0.action'));
        DB::table('membership_permissions')->insert(['membership_id' => $tree['agent']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'reports.audit')->value('id'), 'allowed' => false]);
        $this->asPortalUser($tree['agent']);
        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk();
        $actions = collect($response->json('data.recent_operations'))->pluck('action');
        $this->assertContains('wallets.transfer', $actions);
        $this->assertContains('account.update', $actions);
        $this->assertNotContains('security.update', $actions);
        $this->assertNotContains($tree['other']->name, collect($response->json('data.recent_operations'))->pluck('account_name'));
    }

    public function test_dashboard_and_financial_filters_reject_dollars_and_options_offer_only_iqd(): void
    {
        $tree = $this->setupTree();
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/reports/options')->assertOk()->assertJsonPath('data.currencies', ['IQD']);
        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.currencies', ['IQD']);
        foreach (['dashboard/summary', 'reports/summary', 'finance/wallets', 'sales'] as $path) {
            $this->getJson('/api/v1/'.$path.'?currency=USD')->assertUnprocessable()->assertJsonValidationErrors('currency');
        }
    }

    public function test_recent_audit_events_are_limited_and_keep_latest_ids_when_times_match(): void
    {
        $tree = $this->setupTree();
        $lastId = null;
        for ($index = 0; $index < 35; $index++) {
            $lastId = DB::table('audit_logs')->insertGetId(['user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'subject_account_id' => $tree['pos']->id, 'action' => 'account.update', 'portal' => 'admin', 'created_at' => '2026-10-10 08:00:00']);
        }
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonCount(30, 'data.recent_operations')->assertJsonPath('data.recent_operations.0.key', 'audit:'.$lastId);
    }

    public function test_activity_display_limits_include_all_and_preserve_scope_safe_details_and_permissions(): void
    {
        $tree = $this->setupTree();
        $lastId = null;
        for ($index = 0; $index < 125; $index++) {
            $lastId = DB::table('audit_logs')->insertGetId(['user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'subject_account_id' => $tree['pos']->id, 'action' => 'account.update', 'portal' => 'admin', 'created_at' => '2026-10-10 08:00:00', 'details' => json_encode(['version' => 2, 'reason' => 'تصحيح اسم الحساب', 'credential' => 'HIDDEN_SECRET', 'phone' => 'PRIVATE_PHONE'])]);
        }
        DB::table('audit_logs')->insert(['user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'subject_account_id' => $tree['other']->id, 'action' => 'account.update', 'portal' => 'admin', 'created_at' => '2026-10-10 09:00:00']);
        $this->asPortalUser($tree['agent']);
        foreach ([10, 20, 50, 100, 'all'] as $limit) {
            $response = $this->getJson('/api/v1/dashboard/activity?limit='.$limit)->assertOk()->assertJsonCount($limit === 'all' ? 125 : $limit, 'data')->assertJsonPath('data.0.key', 'audit:'.$lastId)
                ->assertJsonPath('data.0.summary_fields.0.value', '2')->assertJsonPath('data.0.summary_fields.1.value', 'تصحيح اسم الحساب');
            foreach (['HIDDEN_SECRET', 'PRIVATE_PHONE', $tree['other']->name] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->getJson('/api/v1/dashboard/activity?limit=30')->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/activity?limit=all&account_id='.$tree['other']->id)->assertUnprocessable();
        DB::table('membership_permissions')->insert(['membership_id' => $tree['agent']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'dashboard.view')->value('id'), 'allowed' => false]);
        $this->asPortalUser($tree['agent']);
        $this->getJson('/api/v1/dashboard/activity?limit=all')->assertForbidden();
    }

    public function test_recent_sale_audit_preserves_the_amount_without_duplicating_the_sale_record(): void
    {
        $tree = $this->setupTree();
        $sale = $this->sale($tree);
        DB::table('audit_logs')->insert(['user_id' => $tree['seller']->id, 'account_id' => $tree['pos']->id, 'subject_account_id' => $tree['pos']->id, 'action' => 'sell.create', 'portal' => 'pos', 'created_at' => '2026-10-10 08:00:00', 'details' => json_encode(['sale_id' => $sale->id])]);
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonCount(1, 'data.recent_operations')
            ->assertJsonPath('data.recent_operations.0.action', 'sell.create')->assertJsonPath('data.recent_operations.0.amount', '120.02')
            ->assertJsonPath('data.recent_operations.0.product_name', $tree['product']->name);
    }

    public function test_authentication_and_catalog_preserve_all_52_original_sections_and_unavailable_counts_are_null(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/reports/summary')->assertUnauthorized();
        $tree = $this->setupTree();
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/reports/options')->assertOk()->assertJsonCount(52, 'data.sections')->assertJsonCount(5, 'data.groups');
        $summary = $this->getJson('/api/v1/reports/summary')->assertOk()->json('data.sections');
        $this->assertCount(52, $summary);
        foreach ($summary as $section) {
            if (! $section['available']) {
                $this->assertNull($section['count']);
                $this->assertNotEmpty($section['reason']);

                continue;
            }
            $this->getJson('/api/v1/reports/sections/'.$section['id'].'/rows')->assertOk()->assertJsonPath('meta.current_page', 1);
        }
    }

    public function test_sales_are_scoped_to_seller_account_and_reserved_sales_are_not_financial_movements(): void
    {
        $tree = $this->setupTree();
        $own = $this->sale($tree);
        $this->sale($tree, ['account_id' => $tree['other']->id, 'main_account_id' => $tree['other']->id]);
        $this->sale($tree, ['issued_at' => null, 'status' => 'Reserved']);
        $this->asPortalUser($tree['seller']);
        $result = $this->getJson('/api/v1/reports/sections/sales/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sales', '120.02')->assertJsonPath('data.0.profit', '20.01');
        $this->assertArrayNotHasKey('cost', $result->json('data.0'));
        $this->getJson('/api/v1/reports/sections/sales/rows?agent_id='.$tree['other']->id)->assertNotFound();
        $this->getJson('/api/v1/reports/sections/network/rows')->assertNotFound();
        $this->getJson('/api/v1/reports/sections/sales/rows/'.$own->id)->assertOk();
    }

    public function test_inclusive_baghdad_dates_currency_and_snapshot_filters(): void
    {
        $tree = $this->setupTree();
        $this->sale($tree, ['issued_at' => '2026-10-05 20:59:59']);
        $this->sale($tree, ['issued_at' => '2026-10-05 21:00:00']);
        $this->sale($tree, ['issued_at' => '2026-10-06 20:59:59']);
        $this->sale($tree, ['issued_at' => '2026-10-06 21:00:00']);
        $this->sale($tree, ['currency' => 'USD', 'issued_at' => '2026-10-06 12:00:00']);
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/reports/sections/sales/rows?from=2026-10-06&to=2026-10-06')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reports/sections/sales/rows?currency=USD&from=2026-10-06&to=2026-10-06')->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->getJson('/api/v1/reports/sections/network/rows?from=2030-01-01&to=2030-01-02')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reports/sections/sales/rows?from=2026-10-07&to=2026-10-06')->assertUnprocessable();
        $this->getJson('/api/v1/reports/sections/sales/rows?detail_filter=main')->assertUnprocessable();
        $this->getJson('/api/v1/reports/sections/sales/rows?sort=secret')->assertUnprocessable();
    }

    public function test_export_includes_all_filtered_sorted_rows_and_only_authorized_columns(): void
    {
        $tree = $this->setupTree();
        for ($i = 1; $i <= 24; $i++) {
            $this->sale($tree, ['quantity' => $i]);
        }
        $this->asPortalUser($tree['seller']);
        $this->getJson('/api/v1/reports/sections/sales/rows?per_page=20')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 24);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales'], 'sort' => 'quantity', 'direction' => 'desc', 'visible_columns' => ['quantity', 'profit']])->assertOk()->assertJsonCount(24, 'data.sections.0.rows')->assertJsonPath('data.sections.0.rows.0.quantity', 24)->assertJsonPath('data.sections.0.rows.23.quantity', 1);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales'], 'visible_columns' => ['cost']])->assertUnprocessable();
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['network']])->assertNotFound();
        $permission = DB::table('permissions')->where('name', 'reports.export')->value('id');
        DB::table('membership_permissions')->insert(['membership_id' => $tree['seller']->membership->id, 'permission_id' => $permission, 'allowed' => false]);
        $this->asPortalUser($tree['seller']);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales']])->assertForbidden();
    }

    public function test_price_change_json_columns_preserve_null_old_price_and_exact_minor_values(): void
    {
        $tree = $this->setupTree();
        PriceRequest::factory()->create(['account_id' => $tree['main']->id, 'creator_id' => $tree['agent']->id, 'changes' => [['product_id' => $tree['product']->id, 'old_minor' => null, 'price_minor' => 12345]]]);
        $this->asPortalUser($tree['admin']);
        $this->getJson('/api/v1/reports/sections/prices-changes/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price', '123.45')->assertJsonPath('data.0.old', null)->assertJsonPath('data.0.difference', null);
    }

    public function test_dashboard_uses_real_issued_sales_and_wallet_available_balance_with_original_pos_order(): void
    {
        $tree = $this->setupTree();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->sale($tree);
        $this->sale($tree, ['issued_at' => null, 'status' => 'Reserved']);
        DB::transaction(function () use ($tree): void {
            $ledger = app(Ledger::class);
            $own = $ledger->wallet($tree['pos']->id, 'voucher', 'IQD');
            $external = $ledger->wallet($tree['system']->id, 'voucher', 'IQD', 'external');
            $ledger->pair($tree['admin'], 'stock_load', 'report-fixture', [], $external, $own, 100000, 'Test stock funding');
            $own->fresh()->update(['held_minor' => 12002, 'version' => $own->fresh()->version + 1]);
        });
        $this->asPortalUser($tree['seller']);
        $cards = $this->getJson('/api/v1/dashboard/summary')->assertOk()->assertJsonCount(7, 'data.chart')->assertJsonPath('data.availability.digital_services', true)->json('data.cards');
        $this->assertSame(['balance', 'sales', 'quantity', 'failed', 'reprints', 'funding', 'digital:rabiaa', 'digital:topup'], array_column($cards, 'key'));
        $this->assertSame('879.98', $cards[0]['value']);
        $this->assertSame('120.02', $cards[1]['value']);
        $this->assertSame(1, $cards[2]['value']);
        $this->assertSame('0.00', $cards[6]['value']);
        $this->assertSame('0.00', $cards[7]['value']);
    }

    public function test_decimal_formatter_preserves_large_totals_and_never_coerces_float(): void
    {
        $this->assertSame('900719925474099.31', ReportDataset::decimal('90071992547409931'));
        $this->assertSame('-0.01', ReportDataset::decimal(-1));
        $this->assertNull(ReportDataset::decimal(null));
        $this->expectException(\UnexpectedValueException::class);
        ReportDataset::decimal(1.25);
    }

    public function test_cost_denial_masks_non_pos_profit_in_rows_detail_export_and_dashboard_while_pos_keeps_own_profit(): void
    {
        $tree = $this->setupTree();
        $sale = $this->sale($tree);
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $permission = DB::table('permissions')->where('name', 'data.cost')->value('id');
        DB::table('membership_permissions')->insert(['membership_id' => $tree['agent']->membership->id, 'permission_id' => $permission, 'allowed' => false]);
        $this->asPortalUser($tree['agent']);
        $row = $this->getJson('/api/v1/reports/sections/sales/rows')->assertOk()->assertJsonPath('data.0.profit', null)->assertJsonPath('data.0.margin', null)->json('data.0');
        $this->assertArrayNotHasKey('cost', $row);
        $detail = $this->getJson('/api/v1/reports/sections/sales/rows/'.$sale->id)->assertOk()->assertJsonPath('data.profit', null)->json();
        $profitColumn = collect($detail['columns'])->firstWhere('key', 'profit');
        $this->assertTrue($profitColumn['protected']);
        $this->assertFalse($profitColumn['source_available']);
        $this->postJson('/api/v1/reports/export', ['section_ids' => ['sales'], 'visible_columns' => ['profit', 'margin']])->assertOk()->assertJsonPath('data.sections.0.rows.0.profit', null);
        $cards = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards');
        $profit = collect($cards)->firstWhere('key', 'profit');
        $this->assertNull($profit['value']);
        $this->assertFalse($profit['available']);
        $this->asPortalUser($tree['seller']);
        $this->getJson('/api/v1/reports/sections/sales/rows')->assertOk()->assertJsonPath('data.0.profit', '20.01');
    }

    private function scopedEmployee(array $tree): User
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $tree['main']->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view', 'reports.view', 'reports.sales', 'reports.inventory', 'reports.network', 'reports.users', 'reports.export', 'sales.view', 'staff.view', 'inventory.view', 'dashboard.view', 'wallets.view', 'data.profit']);
        $employee = User::factory()->create();
        $membership = AccountMembership::create(['account_id' => $tree['main']->id, 'user_id' => $employee->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee', 'permission_profile_id' => $profile->id, 'include_descendants' => false, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $membership->id, 'account_id' => $tree['pos']->id]);

        return $employee;
    }

    public function test_employee_scope_does_not_expand_to_employer_stock_other_sellers_or_upstream_identity(): void
    {
        $tree = $this->setupTree();
        $own = $this->sale($tree);
        $foreign = $this->sale($tree, ['account_id' => $tree['other']->id, 'main_account_id' => $tree['other']->id]);
        $employee = $this->scopedEmployee($tree);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/reports/options')->assertOk()->assertJsonCount(1, 'data.accounts')->assertJsonPath('data.accounts.0.id', $tree['pos']->id);
        $this->getJson('/api/v1/reports/sections/sales/rows')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/reports/sections/sales/rows/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/reports/sections/inventory/rows')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/sections/network-pos/rows')->assertOk()->assertJsonPath('data.0.agent', null);
        $this->getJson('/api/v1/reports/sections/users/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tree['seller']->id)->assertJsonPath('data.0.granted', count($tree['seller']->membership->permissions()));
        $cards = $this->getJson('/api/v1/dashboard/summary')->assertOk()->json('data.cards');
        $balance = collect($cards)->firstWhere('key', 'balance');
        $this->assertNull($balance['value']);
        $this->assertFalse($balance['available']);
    }

    public function test_stock_report_never_exports_pin_secret_payloads_and_scoped_batch_relations_are_real(): void
    {
        $tree = $this->setupTree();
        $source = OrderSource::factory()->create(['network_account_id' => $tree['main']->id, 'provider_id' => $tree['product']->provider_id]);
        $batch = StockBatch::factory()->create(['account_id' => $tree['main']->id, 'product_id' => $tree['product']->id, 'source_id' => $source->id]);
        $card = StockCard::factory()->create(['batch_id' => $batch->id, 'account_id' => $tree['main']->id, 'product_id' => $tree['product']->id, 'secret' => ['pin' => 'NEVER_EXPORT_PIN', 'cvc' => 'NEVER_EXPORT_CVC']]);
        $this->asPortalUser($tree['admin']);
        $response = $this->getJson('/api/v1/reports/sections/inventory-cards/rows')->assertOk()->assertJsonPath('data.0.internal', $card->id);
        $this->assertStringNotContainsString('NEVER_EXPORT', $response->getContent());
        $this->assertArrayNotHasKey('secret', $response->json('data.0'));
        $detail = $this->getJson('/api/v1/reports/sections/inventory/rows/'.$batch->id)->assertOk();
        $related = collect($detail->json('related'))->firstWhere('id', 'inventory-cards');
        $this->assertSame(1, $related['total']);
        $this->assertSame($card->id, $related['rows'][0]['internal']);
        $export = $this->postJson('/api/v1/reports/export', ['section_ids' => ['inventory-cards']])->assertOk();
        $this->assertStringNotContainsString('NEVER_EXPORT', $export->getContent());
    }

    public function test_funding_and_invoice_collection_reports_use_real_ledger_kinds_and_customer_account(): void
    {
        $tree = $this->setupTree();
        DB::transaction(function () use ($tree): void {
            $ledger = app(Ledger::class);
            $external = $ledger->wallet($tree['system']->id, 'voucher', 'IQD', 'external');
            $agent = $ledger->wallet($tree['main']->id, 'voucher', 'IQD');
            $seller = $ledger->wallet($tree['pos']->id, 'voucher', 'IQD');
            $ledger->pair($tree['admin'], 'funding', 'report-funding', [], $external, $agent, 10000, 'Funding document');
            $ledger->pair($tree['agent'], 'transfer', 'BULK:report-transfer', [], $agent, $seller, 2500, 'Bulk document');
        });
        $invoice = Invoice::factory()->create(['account_id' => $tree['main']->id, 'creator_id' => $tree['admin']->id, 'kind' => 'receivable', 'supplier' => '', 'amount_minor' => 12345]);
        $this->asPortalUser($tree['admin']);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', ['version' => 1, 'amount' => '123.45', 'reference' => 'Receipt document', 'idempotency_key' => 'report-invoice-settlement'])->assertOk();
        $this->getJson('/api/v1/reports/sections/wallets-transfers/rows')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reports/sections/wallets-groups/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '25.00');
        $this->getJson('/api/v1/reports/sections/wallets-collections/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.account', $tree['main']->name)->assertJsonPath('data.0.amount', '123.45');
        $this->asPortalUser($tree['agent']);
        $this->getJson('/api/v1/reports/sections/wallets-collections/rows')->assertOk()->assertJsonCount(1, 'data');
        $this->asPortalUser($tree['seller']);
        $this->getJson('/api/v1/reports/sections/wallets-collections/rows')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_print_rules_read_object_targets_and_hide_targets_outside_employee_scope(): void
    {
        $tree = $this->setupTree();
        $rule = PrintRule::factory()->create(['name' => 'Visible print rule', 'targets' => [['kind' => 'pos', 'id' => $tree['pos']->id], ['kind' => 'tree', 'id' => $tree['other']->id]], 'active' => true]);
        PrintRule::factory()->create(['name' => 'FORBIDDEN_RULE', 'targets' => [['kind' => 'tree', 'id' => $tree['other']->id]]]);
        $employee = $this->scopedEmployee($tree);
        $employee->membership->update(['account_id' => $tree['system']->id]);
        $employee->membership->profile->update(['account_id' => $tree['system']->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($employee->membership->permission_profile_id, ['account.view', 'reports.view', 'reports.operations', 'security.view']);
        $this->asPortalUser($employee);
        $result = $this->getJson('/api/v1/reports/sections/operations-printing/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rule->id)->assertJsonPath('data.0.targets', $tree['pos']->name)->assertJsonPath('data.0.maxCards', 10);
        $this->assertStringNotContainsString('FORBIDDEN_RULE', $result->getContent());
        $this->assertStringNotContainsString($tree['other']->name, $result->getContent());
    }

    public function test_support_bodies_obey_ticket_and_reply_audience_and_notice_body_obeys_exact_recipient(): void
    {
        $tree = $this->setupTree();
        $own = SupportTicket::factory()->create(['sender_id' => $tree['agent']->id, 'origin_id' => $tree['main']->id, 'recipient_id' => $tree['system']->id, 'route_stack' => [$tree['main']->id, $tree['system']->id], 'description' => 'Visible original ticket']);
        $foreign = SupportTicket::factory()->create(['origin_id' => $tree['other']->id, 'recipient_id' => $tree['system']->id, 'route_stack' => [$tree['other']->id, $tree['system']->id], 'description' => 'FORBIDDEN_FOREIGN_TICKET']);
        $reply = SupportMessage::factory()->create(['ticket_id' => $own->id, 'user_id' => $tree['admin']->id, 'account_id' => $tree['system']->id, 'body' => 'Visible audience reply']);
        DB::table('support_message_audience')->insert(['message_id' => $reply->id, 'account_id' => $tree['main']->id]);
        SupportMessage::factory()->create(['ticket_id' => $own->id, 'body' => 'FORBIDDEN_REPLY']);
        Notice::factory()->toUser($tree['agent'])->create(['sender_id' => $tree['admin']->id, 'body' => 'Visible notice']);
        Notice::factory()->toUser($tree['seller'])->create(['sender_id' => $tree['admin']->id, 'body' => 'FORBIDDEN_NOTICE']);
        $this->asPortalUser($tree['agent']);
        $tickets = $this->getJson('/api/v1/reports/sections/support/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Visible original ticket');
        $replies = $this->getJson('/api/v1/reports/sections/support-replies/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Visible audience reply');
        $notices = $this->getJson('/api/v1/reports/sections/operations-notifications/rows')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Visible notice');
        foreach ([$tickets, $replies, $notices] as $response) {
            $this->assertStringNotContainsString('FORBIDDEN', $response->getContent());
        }
        $this->getJson('/api/v1/reports/sections/support/rows/'.$foreign->id)->assertNotFound();
    }
}
