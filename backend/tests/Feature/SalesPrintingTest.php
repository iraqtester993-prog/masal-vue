<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\AccountAttachment;
use App\Models\CatalogProduct;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\OrderSource;
use App\Models\Sales\PrintPolicy;
use App\Models\Sales\ReprintRequest;
use App\Models\Sales\Sale;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Services\AuditLogger;
use App\Services\Finance\Ledger;
use App\Services\Sales\ReceiptDesign;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class SalesPrintingTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(int $quantity = 12, string $fund = '1000.00', bool $directPos = false): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $directPos ? $main : $sub);
        foreach ([$main, $sub, $pos] as $account) {
            $account->update(['city' => 'بغداد']);
        }
        $admin = $this->userFor($system);
        $owner = $this->userFor($main);
        $seller = $this->userFor($sub);
        $point = $this->userFor($pos);
        $product = CatalogProduct::factory()->create(['minimum_price' => '100.00', 'daily_quantity' => 100, 'receipt_header' => 'Original header', 'receipt_footer' => 'Original footer']);
        $source = OrderSource::factory()->create(['provider_id' => $product->provider_id, 'network_account_id' => $main->id]);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        $this->asPortalUser($owner);
        $this->postJson('/api/v1/finance/price-requests', ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '100.25', 'expected_price' => null]], 'idempotency_key' => 'fixture-approved-price'])->assertCreated()->assertJsonPath('data.status', 'approved');
        $batch = StockBatch::factory()->create(['account_id' => $main->id, 'product_id' => $product->id, 'source_id' => $source->id, 'quantity' => $quantity, 'cost_minor' => 9000, 'cost_total_minor' => 9000 * $quantity, 'load_price_minor' => 10025, 'amount_minor' => 10025 * $quantity]);
        $cards = StockCard::factory()->count($quantity)->create(['batch_id' => $batch->id, 'account_id' => $main->id, 'product_id' => $product->id, 'cost_minor' => 9000, 'credit_minor' => 10025]);
        DB::transaction(function () use ($admin, $system, $main, $quantity): void {
            $ledger = app(Ledger::class);
            $ledger->pair($admin, 'stock-import', 'fixture-stock-credit', ['batch' => 1], $ledger->wallet($system->id, 'voucher', 'IQD', 'external'), $ledger->wallet($main->id, 'voucher', 'IQD'), 10025 * $quantity, 'Test stock import');
        });
        $this->asPortalUser($owner);
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => $fund, 'reference' => 'Fund seller', 'idempotency_key' => 'fixture-seller-funding'])->assertCreated();
        $this->asPortalUser($seller);

        return compact('system', 'main', 'sub', 'pos', 'admin', 'owner', 'seller', 'point', 'product', 'batch', 'cards');
    }

    private function input(CatalogProduct $product, string $key = 'sale-test-001', int $quantity = 1): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity, 'price_version' => 1, 'expected_price' => '100.25', 'retail_price' => '110.50', 'idempotency_key' => $key];
    }

    private function mutate(int $id, string $path, array $extra = [], string $key = 'sale-mutation-001'): array
    {
        return $this->postJson('/api/v1/sales/'.$id.'/'.$path, ['version' => Sale::findOrFail($id)->version, 'idempotency_key' => $key] + $extra)->assertOk()->json('data');
    }

    public function test_receipt_copy_needs_no_approval_and_never_changes_sale_stock_or_balances(): void
    {
        $f = $this->fixture(12, '100.00', true);
        $this->asPortalUser($f['owner']);
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['main']->id, 'to_account_id' => $f['pos']->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => '500.00', 'reference' => 'POS test funding', 'idempotency_key' => 'copy-pos-fund'])->assertCreated();
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'copy-original-sale'))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/'.$id.'/print/copy', ['idempotency_key' => 'copy-before-result'])->assertConflict();
        Sale::findOrFail($id)->update(['status' => 'Print Failed', 'print_pending' => false]);
        $wallets = DB::table('finance_wallets')->orderBy('id')->get()->toJson();
        $cards = DB::table('stock_cards')->orderBy('id')->get()->toJson();
        $sale = Sale::findOrFail($id)->toJson();
        $transactions = DB::table('finance_transactions')->count();
        $payload = ['idempotency_key' => 'copy-existing-receipt'];
        $copy = $this->postJson('/api/v1/sales/'.$id.'/print/copy', $payload)->assertOk()->json('data');
        $this->postJson('/api/v1/sales/'.$id.'/print/copy', $payload)->assertOk()->assertJsonPath('data.copy_id', $copy['copy_id']);
        $this->assertSame($wallets, DB::table('finance_wallets')->orderBy('id')->get()->toJson());
        $this->assertSame($cards, DB::table('stock_cards')->orderBy('id')->get()->toJson());
        $this->assertSame($sale, Sale::findOrFail($id)->toJson());
        $this->assertSame($transactions, DB::table('finance_transactions')->count());
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, ReprintRequest::count());
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales/'.$id.'/print/copy', ['idempotency_key' => 'copy-foreign-sale'])->assertNotFound();
    }

    public function test_unauthenticated_sales_api_returns_401(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/sales')->assertUnauthorized();
    }

    public function test_pos_funding_approval_debits_agent_once_and_issue_consumes_pos_credit_and_shared_stock_only(): void
    {
        $f = $this->fixture(12, '1000.00', true);
        DB::table('finance_policy')->where('id', 1)->update(['amounts_minor' => json_encode([20000]), 'daily_limit' => 2]);
        $agentBefore = (int) DB::table('finance_wallets')->where('account_id', $f['main']->id)->where('service', 'voucher')->where('kind', 'account')->value('balance_minor');
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.stock_available', 12)->assertJsonPath('data.0.wallet_available', '0.00');
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'unfunded-pos-sale'))->assertUnprocessable()->assertJsonPath('message', 'رصيدك غير كافٍ لإصدار العدد المطلوب من البطاقات.');
        $funding = ['service' => 'voucher', 'currency' => 'IQD', 'amount' => '200.00', 'idempotency_key' => 'pos-voucher-request'];
        $requestId = $this->postJson('/api/v1/finance/funding-requests', $funding)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/finance/funding-requests', $funding)->assertCreated()->assertJsonPath('data.id', $requestId);
        $this->asPortalUser($f['owner']);
        $review = ['version' => 1, 'decision' => 'approve', 'reference' => 'Approved POS voucher funding', 'idempotency_key' => 'pos-voucher-approved'];
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', $review)->assertOk();
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', $review)->assertOk();
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', array_replace($review, ['idempotency_key' => 'pos-voucher-reapprove']))->assertConflict();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'kind' => 'account', 'service' => 'voucher', 'balance_minor' => $agentBefore - 20000]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['pos']->id, 'kind' => 'account', 'service' => 'voucher', 'balance_minor' => 20000]);
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $payload = $this->input($f['product'], 'funded-pos-issue');
        $saleId = $this->postJson('/api/v1/sales', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.id', $saleId);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['pos']->id, 'kind' => 'account', 'service' => 'voucher', 'balance_minor' => 9975, 'held_minor' => 0]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'kind' => 'account', 'service' => 'voucher', 'balance_minor' => $agentBefore - 20000]);
        $this->assertSame(11, StockCard::where('account_id', $f['main']->id)->where('status', 'Available')->count());
        $this->assertSame(0, StockCard::where('account_id', $f['pos']->id)->count());
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'funded-pos-over-balance'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'funding')->count());
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_pos_voucher_funding_with_insufficient_agent_balance_preserves_request_and_both_wallets(): void
    {
        $f = $this->fixture(12, '1000.00', true);
        DB::table('finance_policy')->where('id', 1)->update(['amounts_minor' => json_encode([30000])]);
        $this->asPortalUser($f['point']);
        $requestId = $this->postJson('/api/v1/finance/funding-requests', ['service' => 'voucher', 'currency' => 'IQD', 'amount' => '300.00', 'idempotency_key' => 'pos-voucher-over-agent'])->assertCreated()->json('data.id');
        $this->asPortalUser($f['owner']);
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', ['version' => 1, 'decision' => 'approve', 'reference' => 'Over available balance', 'idempotency_key' => 'pos-voucher-insufficient-approve'])->assertUnprocessable();
        $this->assertDatabaseHas('finance_funding_requests', ['id' => $requestId, 'status' => 'pending', 'version' => 1, 'transaction_id' => null]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'service' => 'voucher', 'balance_minor' => 20300]);
        $this->assertSame(0, DB::table('finance_transactions')->where('kind', 'funding')->count());
        $this->assertSame(0, (int) DB::table('finance_wallets')->where('account_id', $f['pos']->id)->sum('balance_minor'));
    }

    public function test_pos_sees_granted_empty_categories_but_not_ungranted_categories_and_stock_recovers_without_regrant(): void
    {
        $f = $this->fixture();
        $f['product']->provider->update(['connection' => 'API']);
        StockCard::where('batch_id', $f['batch']->id)->delete();
        $other = CatalogProduct::factory()->create(['provider_id' => $f['product']->provider_id]);
        $mainRule = DB::table('catalog_account_rules')->where('target_account_id', $f['main']->id)->value('id');
        DB::table('catalog_rule_products')->insert(['rule_id' => $mainRule, 'product_id' => $other->id]);
        $posRule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $f['pos']->id, 'authority_account_id' => $f['main']->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $posRule, 'product_id' => $f['product']->id]);
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $f['product']->id)->assertJsonPath('data.0.stock_available', 0);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'empty-granted-pos-sale'))->assertUnprocessable();
        StockCard::factory()->create(['batch_id' => $f['batch']->id, 'account_id' => $f['main']->id, 'product_id' => $f['product']->id, 'cost_minor' => 9000, 'credit_minor' => 10025]);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_available', 1);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_funded_stock_cannot_bypass_disabled_sales_printing_or_daily_category_and_provider_amount_limits(): void
    {
        $f = $this->fixture();
        $base = PrintPolicy::findOrFail(1);
        $original = $base->settings;
        foreach (['sales_enabled', 'printing_enabled'] as $setting) {
            $base->update(['settings' => array_replace($original, [$setting => false])]);
            $this->postJson('/api/v1/sales', $this->input($f['product'], 'disabled-'.$setting))->assertConflict();
        }
        $base->update(['settings' => array_replace($original, ['provider_daily_minor' => ['IQD' => 10024]])]);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'over-provider-amount'))->assertUnprocessable();
        $base->update(['settings' => $original]);
        $f['product']->update(['daily_amount' => '100.24']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'over-category-amount'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->count());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'service' => 'voucher', 'balance_minor' => 100000, 'held_minor' => 0]);
        $this->assertSame(0, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_custom_daily_policy_cannot_relax_the_category_daily_quantity_limit(): void
    {
        $f = $this->fixture();
        $f['product']->update(['daily_quantity' => 1]);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/sales/print-rules', ['version' => 0, 'name' => 'More permissive account daily policy', 'active' => true, 'targets' => [['kind' => 'tree', 'id' => $f['main']->id]], 'policy' => $this->printPolicy(['daily_cards' => 10, 'daily_mode' => 'network']), 'idempotency_key' => 'custom-category-limit-rule'])->assertCreated();
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'custom-over-category-limit', 2))->assertUnprocessable()->assertJsonPath('message', 'تم بلوغ الحد اليومي لكمية الفئة.');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->count());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'service' => 'voucher', 'balance_minor' => 100000, 'held_minor' => 0]);
    }

    public function test_assigned_stock_remains_visible_when_agent_price_is_below_minimum_without_allowing_a_sale(): void
    {
        $f = $this->fixture();
        $f['product']->provider->update(['connection' => 'API']);
        $f['product']->update(['minimum_price' => '100.30']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $f['product']->id)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.price_version', null)
            ->assertJsonPath('data.0.availability_reason', 'price_below_minimum')
            ->assertJsonPath('data.0.stock_available', 12);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'below-minimum-visible'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->count());
        $f['product']->update(['minimum_price' => '100.00']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.price', '100.25')->assertJsonPath('data.0.availability_reason', null);
    }

    public function test_catalog_query_count_stays_bounded_and_next_request_reads_changed_print_policy(): void
    {
        $fixture = $this->fixture();
        $this->asPortalUser($fixture['point']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data');
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();
        $rule = DB::table('catalog_account_rules')->where('target_account_id', $fixture['main']->id)->value('id');
        foreach (CatalogProduct::factory()->count(24)->create(['provider_id' => $fixture['product']->provider_id]) as $product) {
            DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(25, 'data');
        $this->assertLessThanOrEqual($baseline + 5, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $policy = PrintPolicy::findOrFail(1);
        $settings = $policy->policy;
        $settings['max_cards'] = 7;
        $policy->update(['policy' => $settings]);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.print_policy.max_cards', 7);
    }

    public function test_real_sale_consumes_fifo_inventory_and_exact_voucher_credit_once_without_returning_secrets(): void
    {
        $f = $this->fixture();
        $first = $f['cards'][0];
        $second = $f['cards'][1];
        $first->update(['expiry' => now()->addDays(20)->toDateString()]);
        $second->update(['expiry' => now()->addDays(10)->toDateString()]);

        $payload = $this->input($f['product'], 'exact-sale-001', 2);
        $id = $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.total', '200.50')->assertJsonPath('data.pos_profit', '20.50')->assertJsonPath('data.status', 'Print Requested')->json('data.id');
        $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/sales', array_replace($payload, ['quantity' => 1]))->assertConflict();

        $this->assertDatabaseHas('stock_cards', ['id' => $first->id, 'sale_id' => $id, 'status' => 'Issued']);
        $this->assertDatabaseHas('stock_cards', ['id' => $second->id, 'sale_id' => $id, 'status' => 'Issued']);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 79950, 'held_minor' => 0]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->getJson('/api/v1/sales/'.$id)->assertOk()->assertJsonMissingPath('data.cards');
        $this->assertDatabaseHas('stock_batches', ['id' => $f['batch']->id, 'status' => 'Partially Used']);
    }

    public function test_api_company_local_cards_appear_in_pos_and_sell_once_from_inventory(): void
    {
        $f = $this->fixture();
        $f['product']->provider->update(['connection' => 'API']);
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_available', 12)->assertJsonPath('data.0.price', '100.25');
        $this->asPortalUser($f['seller']);
        $payload = $this->input($f['product'], 'api-local-stock-sale');
        $id = $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.total', '100.25')->json('data.id');
        $this->postJson('/api/v1/sales', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 89975]);
        $this->assertSame(1, StockCard::where('sale_id', $id)->where('status', 'Issued')->count());
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_assigned_api_company_without_eligible_local_cards_stays_visible_but_cannot_issue(): void
    {
        $f = $this->fixture();
        $f['product']->provider->update(['connection' => 'API']);
        foreach (['held', 'expired', 'unloaded', 'other-agent', 'wrong-currency'] as $reason) {
            StockCard::where('batch_id', $f['batch']->id)->update(['credit_held' => $reason === 'held', 'expiry' => $reason === 'expired' ? now('Asia/Baghdad')->toDateString() : now()->addYear()->toDateString(), 'account_id' => $reason === 'other-agent' ? $f['system']->id : $f['main']->id]);
            $f['batch']->update(['status' => $reason === 'unloaded' ? 'Pending' : 'Loaded', 'currency' => $reason === 'wrong-currency' ? 'USD' : 'IQD']);
            $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.stock_available', 0);
            $this->postJson('/api/v1/sales', $this->input($f['product'], 'api-unavailable-'.$reason))->assertUnprocessable();
            $this->assertDatabaseCount('sales', 0);
        }
    }

    public function test_paused_digital_category_never_falls_back_to_local_card_printing(): void
    {
        $f = $this->fixture();
        $f['product']->provider->update(['connection' => 'API']);
        $connection = DigitalConnection::factory()->create(['account_id' => $f['system']->id, 'provider_id' => $f['product']->provider_id, 'active' => false]);
        DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $f['product']->id, 'listed' => true, 'active' => false]);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'digital-no-stock-fallback'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->count());
    }

    public function test_reservation_holds_wallet_without_exposing_pin_and_cancellation_releases_stock_and_hold(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'reservation-cancel-001', 2))->assertCreated()->assertJsonMissingPath('data.cards')->json('data.id');
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 100000, 'held_minor' => 20050]);
        $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertConflict();
        $this->postJson('/api/v1/sales/reservations/'.$id.'/cancel', ['version' => 1, 'idempotency_key' => 'cancel-reservation-001'])->assertOk()->assertJsonPath('data.status', 'Cancelled');
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 100000, 'held_minor' => 0]);
        $this->assertSame(12, StockCard::where('status', 'Available')->whereNull('sale_id')->count());
        $this->assertSame(0, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_stock_counts_follow_issued_printed_reprinted_and_delivered_cards_and_only_advertise_salable_stock(): void
    {
        $f = $this->fixture();
        $f['cards'][10]->update(['expiry' => now('Asia/Baghdad')->toDateString()]);
        $f['cards'][11]->update(['credit_held' => true]);
        $id = $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'stock-count-reservation'))->assertCreated()->json('data.id');
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/stock/batches/'.$f['batch']->id)->assertOk()->assertJsonPath('data.available_count', 9)->assertJsonPath('data.reserved_count', 1)->assertJsonPath('data.sold_count', 0);
        $this->getJson('/api/v1/stock/summary')->assertOk()->assertJsonPath('data.available_count', 9)->assertJsonPath('data.available_cost_by_currency.IQD', '810.00');
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', ['version' => 1, 'idempotency_key' => 'stock-count-issue'])->assertOk();
        foreach (['Issued', 'Printed', 'Reprinted', 'Delivered'] as $state) {
            if ($state === 'Printed' || $state === 'Reprinted') {
                if ($state === 'Reprinted') {
                    $request = $this->postJson('/api/v1/sales/'.$id.'/reprint-request', ['version' => Sale::findOrFail($id)->version, 'reason' => 'Customer needs the same receipt', 'idempotency_key' => 'stock-count-reprint'])->assertCreated()->json('data');
                    $this->asPortalUser($f['owner']);
                    $this->postJson('/api/v1/sales/reprint-requests/'.$request['id'].'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'stock-count-approve'])->assertOk();
                    $this->asPortalUser($f['seller']);
                    $this->travel(5)->seconds();
                }
                $attempt = $this->mutate($id, 'print/start', [], 'stock-count-start-'.$state)['attempt_id'];
                $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'stock-count-result-'.$state);
            } elseif ($state === 'Delivered') {
                $this->mutate($id, 'deliver', ['channel' => 'استلام مباشر', 'reference' => 'Physical handoff'], 'stock-count-deliver');
            }
            $this->assertDatabaseHas('stock_cards', ['sale_id' => $id, 'status' => $state]);
            $this->asPortalUser($f['owner']);
            $this->getJson('/api/v1/stock/batches/'.$f['batch']->id)->assertOk()->assertJsonPath('data.available_count', 9)->assertJsonPath('data.sold_count', 1)->assertJsonPath('data.reserved_count', 0);
            $this->asPortalUser($f['seller']);
        }
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_reservation_issue_rejects_changed_quote_and_can_be_cancelled_without_financial_loss(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'reservation-price-001'))->assertCreated()->json('data.id');
        DB::table('finance_prices')->where('product_id', $f['product']->id)->update(['price_minor' => 10100, 'version' => 2]);
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', ['version' => 1, 'idempotency_key' => 'issue-stale-reservation'])->assertConflict();
        $this->assertDatabaseHas('sales', ['id' => $id, 'status' => 'Reserved']);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 100000, 'held_minor' => 10025]);
        $this->postJson('/api/v1/sales/reservations/'.$id.'/cancel', ['version' => 1, 'idempotency_key' => 'cancel-stale-reservation'])->assertOk();
    }

    public function test_system_and_main_cannot_impersonate_seller_or_read_issued_secrets(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        foreach ([$f['admin'], $f['owner']] as $user) {
            $this->asPortalUser($user);
            $this->postJson('/api/v1/sales', $this->input($f['product'], 'impersonated-sale-001'))->assertForbidden();
            $this->getJson('/api/v1/sales/'.$id)->assertOk()->assertJsonMissingPath('data.cards');
            $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertForbidden();
        }
    }

    public function test_receipt_returns_actual_encrypted_card_fields_to_own_account_only_and_records_audit(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $card = StockCard::where('sale_id', $id)->firstOrFail();
        $pin = $card->secret['pin'];
        $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.cards.0.fields.pin', $pin)->assertJsonPath('data.design.header', 'Original header')->assertJsonPath('data.design.width', 80);
        $this->assertNotNull(Sale::findOrFail($id)->exposed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'sell.receipt', 'subject_account_id' => $f['sub']->id]);
        $this->assertStringNotContainsString($pin, DB::table('stock_cards')->where('id', $card->id)->value('secret'));
    }

    public function test_sale_audit_failure_rolls_back_inventory_finance_and_idempotency_record(): void
    {
        $f = $this->fixture();
        $before = DB::table('finance_transactions')->count();
        $this->mock(AuditLogger::class)->shouldReceive('record')->andThrow(new \RuntimeException('Audit storage failure'));
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'audit-rollback-sale'))->assertStatus(500);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('finance_transactions', $before);
        $this->assertDatabaseMissing('finance_operations', ['idempotency_key' => 'audit-rollback-sale']);
        $this->assertSame(12, StockCard::where('status', 'Available')->whereNull('sale_id')->count());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 100000, 'held_minor' => 0]);
    }

    public function test_held_balance_is_unavailable_for_sale_and_failed_print_does_not_refund_exposed_cards(): void
    {
        $f = $this->fixture(12, '300.00');
        $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'hold-two-cards', 2))->assertCreated();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'over-held-funds'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_print_requires_explicit_started_attempt_and_failure_reason_without_refunding_sale(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/'.$id.'/print/result', ['version' => 2, 'attempt_id' => 1, 'success' => true, 'idempotency_key' => 'result-without-start'])->assertConflict();
        $start = ['version' => 2, 'idempotency_key' => 'print-start-001'];
        $attempt = $this->postJson('/api/v1/sales/'.$id.'/print/start', $start)->assertOk()->assertJsonPath('data.print_pending', true)->json('data.attempt_id');
        $this->postJson('/api/v1/sales/'.$id.'/print/start', $start)->assertOk()->assertJsonPath('data.attempt_id', $attempt);
        $this->postJson('/api/v1/sales/'.$id.'/print/result', ['version' => 3, 'attempt_id' => $attempt, 'success' => false, 'idempotency_key' => 'failed-missing-reason'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $data = ['version' => 3, 'attempt_id' => $attempt, 'success' => false, 'reason' => 'Printer paper jam', 'idempotency_key' => 'failed-print-result'];
        $this->postJson('/api/v1/sales/'.$id.'/print/result', $data)->assertOk()->assertJsonPath('data.status', 'Print Failed')->assertJsonPath('data.print_pending', false);
        $this->postJson('/api/v1/sales/'.$id.'/print/result', $data)->assertOk();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 89975, 'held_minor' => 0]);
        $this->assertSame(1, StockCard::where('sale_id', $id)->where('status', 'Print Failed')->count());
        $this->assertSame(1, DB::table('sales_print_attempts')->count());
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_unknown_print_result_blocks_another_attempt_and_reprint_request(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $this->mutate($id, 'print/start', [], 'pending-print-start');
        $this->travel(3)->seconds();
        $other = $this->postJson('/api/v1/sales', $this->input($f['product'], 'second-pending-sale'))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/'.$other.'/print/start', ['version' => 2, 'idempotency_key' => 'other-print-start'])->assertConflict();
        $this->postJson('/api/v1/sales/'.$id.'/reprint-request', ['version' => 3, 'reason' => 'Result unknown', 'idempotency_key' => 'unknown-reprint-request'])->assertConflict();
        $this->assertDatabaseCount('sales_print_attempts', 1);
        $this->assertSame(0, ReprintRequest::count());
    }

    public function test_pending_print_is_identified_for_its_owner_without_exposing_cards_or_another_accounts_attempt(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'pending-summary-sale'))->assertCreated()->json('data.id');
        $this->mutate($id, 'print/start', [], 'pending-summary-start');
        $this->travel(3)->seconds();
        $other = $this->postJson('/api/v1/sales', $this->input($f['product'], 'pending-summary-other'))->assertCreated()->json('data.id');
        $balance = DB::table('finance_wallets')->where('account_id', $f['sub']->id)->where('service', 'voucher')->value('balance_minor');
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('pending_print.id', $id)->assertJsonMissingPath('pending_print.cards');
        $this->getJson('/api/v1/sales/options?compact=1')->assertOk()->assertJsonPath('data.pending_print.id', $id);
        $this->getJson('/api/v1/sales/'.$other.'/receipt')->assertOk()->assertJsonPath('data.pending_print.id', $id)->assertJsonPath('data.reprint_approved', false)->assertJsonMissingPath('data.pending_print.cards');
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('pending_print', null);
        $this->asPortalUser($f['seller']);
        $this->mutate($id, 'print/result', ['attempt_id' => DB::table('sales_print_attempts')->where('sale_id', $id)->value('id'), 'success' => false, 'reason' => 'User confirmed no paper came out'], 'pending-summary-result');
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('pending_print', null);
        $this->assertSame((int) $balance, (int) DB::table('finance_wallets')->where('account_id', $f['sub']->id)->where('service', 'voucher')->value('balance_minor'));
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_failed_retry_limit_consumes_no_additional_money_and_requires_approval_after_limit(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $attempt = $this->mutate($id, 'print/start', [], 'retry-first-start')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => false, 'reason' => 'No paper'], 'retry-first-result');
        for ($i = 1; $i <= 2; $i++) {
            $this->travel(5)->seconds();
            $this->mutate($id, 'print/retry', [], 'retry-prepare-'.$i);
            $attempt = $this->mutate($id, 'print/start', [], 'retry-start-'.$i)['attempt_id'];
            $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => false, 'reason' => 'Still no paper'], 'retry-result-'.$i);
        }
        $this->travel(5)->seconds();
        $this->postJson('/api/v1/sales/'.$id.'/print/retry', ['version' => Sale::findOrFail($id)->version, 'idempotency_key' => 'retry-over-limit'])->assertUnprocessable();
        $this->assertDatabaseHas('sales', ['id' => $id, 'failed_retry_count' => 2, 'status' => 'Print Failed']);
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 89975]);
    }

    public function test_reprint_request_routes_to_parent_can_escalate_and_only_current_recipient_can_approve(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $attempt = $this->mutate($id, 'print/start', [], 'escalated-initial-start')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => false, 'reason' => 'Printer offline'], 'escalated-initial-result');
        $requestId = $this->postJson('/api/v1/sales/'.$id.'/reprint-request', ['version' => 4, 'reason' => 'Need approved reprint', 'idempotency_key' => 'request-escalated-reprint'])->assertCreated()->assertJsonPath('data.recipient_id', $f['main']->id)->json('data.id');
        $this->postJson('/api/v1/sales/'.$id.'/print/start', ['version' => 5, 'idempotency_key' => 'unapproved-print-start'])->assertConflict();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/sales/reprint-requests/'.$requestId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'admin-jump-review'])->assertForbidden();
        $this->asPortalUser($f['owner']);
        $this->postJson('/api/v1/sales/reprint-requests/'.$requestId.'/escalate', ['version' => 1, 'reason' => 'Review hardware issue', 'idempotency_key' => 'escalate-parent-review'])->assertOk()->assertJsonPath('data.recipient_id', $f['system']->id)->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/sales/reprint-requests/'.$requestId.'/review', ['version' => 2, 'decision' => 'approve', 'idempotency_key' => 'old-parent-review'])->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/sales/reprint-requests/'.$requestId.'/review', ['version' => 2, 'decision' => 'approve', 'idempotency_key' => 'system-final-review'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->asPortalUser($f['seller']);
        $this->travel(5)->seconds();
        $attempt = $this->mutate($id, 'print/start', [], 'approved-reprint-start')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'approved-reprint-result');
        $this->assertDatabaseHas('sales', ['id' => $id, 'status' => 'Reprinted', 'reprints' => 1]);
        $this->assertDatabaseHas('sales_reprint_requests', ['id' => $requestId, 'status' => 'used']);
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->assertNotNull(Sale::findOrFail($id)->first_printed_at);
    }

    public function test_reprint_rejection_restores_previous_status_and_cas_prevents_duplicate_decision(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $attempt = $this->mutate($id, 'print/start', [], 'rejection-initial-start')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'rejection-initial-result');
        $r = $this->postJson('/api/v1/sales/'.$id.'/reprint-request', ['version' => 4, 'reason' => 'Duplicate receipt', 'idempotency_key' => 'rejection-request'])->assertCreated()->json('data.id');
        $this->asPortalUser($f['owner']);
        $data = ['version' => 1, 'decision' => 'reject', 'reason' => 'Receipt already delivered', 'idempotency_key' => 'reject-reprint-decision'];
        $this->postJson('/api/v1/sales/reprint-requests/'.$r.'/review', $data)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson('/api/v1/sales/reprint-requests/'.$r.'/review', $data)->assertOk();
        $this->postJson('/api/v1/sales/reprint-requests/'.$r.'/review', array_replace($data, ['decision' => 'approve', 'idempotency_key' => 'stale-reprint-decision']))->assertConflict();
        $this->assertDatabaseHas('sales', ['id' => $id, 'status' => 'Printed', 'reprints' => 0, 'reprint_request_id' => null]);
    }

    public function test_pos_needs_authenticated_live_device_session_and_expired_session_cannot_issue(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['sub']->id, 'to_account_id' => $f['pos']->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => '300.00', 'reference' => 'Fund POS', 'idempotency_key' => 'fund-pos-for-session'])->assertCreated();
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'no-device-session'))->assertConflict();
        $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk()->assertJsonPath('data.online', true);
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->assertSame(hash('sha256', app('session')->getId()), DB::table('sales_device_sessions')->value('session_hash'));
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'live-pos-sale'))->assertCreated()->assertJsonPath('data.account_id', $f['pos']->id)->assertJsonMissingPath('data.cost');
        $this->travel(91)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'expired-pos-sale'))->assertConflict();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_sale_financial_values_are_database_immutable_after_issue(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $this->expectException(QueryException::class);
        DB::table('sales')->where('id', $id)->update(['credit_minor' => 1]);
    }

    public function test_reservation_issues_its_exact_cards_and_retry_never_debits_twice(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'reserve-issue-001', 2))->assertCreated()->json('data.id');
        $ids = StockCard::where('sale_id', $id)->pluck('id')->all();
        $input = ['version' => 1, 'idempotency_key' => 'issue-reservation-idempotent'];
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', $input)->assertOk()->assertJsonPath('data.status', 'Print Requested');
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', $input)->assertOk();
        $this->assertSame($ids, StockCard::where('sale_id', $id)->pluck('id')->all());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 79950, 'held_minor' => 0]);
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->postJson('/api/v1/sales/reservations/'.$id.'/cancel', ['version' => 2, 'idempotency_key' => 'cancel-issued-sale'])->assertConflict();
    }

    public function test_expired_stock_is_excluded_and_expired_reservation_can_be_released_without_reissue(): void
    {
        $f = $this->fixture(12, '1000.00');
        $f['cards'][0]->update(['expiry' => now('Asia/Baghdad')->toDateString()]);
        $id = $this->postJson('/api/v1/sales/reservations', $this->input($f['product'], 'exclude-expired-stock'))->assertCreated()->json('data.id');
        $card = StockCard::where('sale_id', $id)->firstOrFail();
        $this->assertNotSame($f['cards'][0]->id, $card->id);
        $card->update(['expiry' => now('Asia/Baghdad')->toDateString()]);
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', ['version' => 1, 'idempotency_key' => 'expired-reservation-issue'])->assertConflict();
        $this->postJson('/api/v1/sales/reservations/'.$id.'/cancel', ['version' => 1, 'idempotency_key' => 'expired-reservation-cancel'])->assertOk();
        $this->assertSame(0, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_stale_quote_unknown_price_and_unsafe_payload_never_change_inventory_or_wallet(): void
    {
        $f = $this->fixture();
        $input = $this->input($f['product'], 'stale-quote-sale');
        $this->postJson('/api/v1/sales', array_replace($input, ['expected_price' => '100.24']))->assertConflict();
        $this->postJson('/api/v1/sales', $input + ['account_id' => $f['pos']->id])->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->postJson('/api/v1/sales', array_replace($input, ['retail_price' => 12.50]))->assertUnprocessable()->assertJsonValidationErrors('retail_price');
        DB::table('finance_prices')->where('product_id', $f['product']->id)->delete();
        $this->postJson('/api/v1/sales', $input)->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->count());
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['sub']->id, 'balance_minor' => 100000, 'held_minor' => 0]);
    }

    public function test_daily_product_limit_and_velocity_are_enforced_then_reset_on_baghdad_day(): void
    {
        $f = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 20:59:55', 'UTC'));
        $f['product']->update(['daily_quantity' => 2]);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'first-daily-limit-sale'))->assertCreated();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'velocity-blocked-sale'))->assertStatus(429);
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'second-daily-limit-sale'))->assertCreated();
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'new-baghdad-day-sale'))->assertCreated();
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'new-day-second-sale'))->assertCreated();
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'new-day-over-limit'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 4);
    }

    private function printPolicy(array $overrides = []): array
    {
        return array_replace(['failed_retries' => 2, 'max_cards' => 10, 'interval_seconds' => 5, 'daily_cards' => 0, 'daily_mode' => 'account', 'daily_product_mode' => 'all', 'daily_products' => []], $overrides);
    }

    public function test_global_print_capacity_is_checked_before_issuing_or_charging_a_batch(): void
    {
        $f = $this->fixture();
        $policy = PrintPolicy::findOrFail(1);
        $policy->update(['policy' => array_replace($policy->policy, ['daily_cards' => 1, 'max_cards' => 10])]);
        $before = DB::table('finance_wallets')->orderBy('id')->get()->toJson();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-print-over-cap', 2))->assertUnprocessable();
        $this->assertSame($before, DB::table('finance_wallets')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(12, StockCard::where('status', 'Available')->whereNull('sale_id')->count());
    }

    public function test_already_issued_unprinted_cards_reserve_global_capacity_before_another_charge(): void
    {
        $f = $this->fixture();
        $policy = PrintPolicy::findOrFail(1);
        $policy->update(['policy' => array_replace($policy->policy, ['daily_cards' => 1])]);
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-first-ready-sale'))->assertCreated()->json('data.id');
        $this->travel(4)->seconds();
        $before = DB::table('finance_wallets')->orderBy('id')->get()->toJson();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-second-ready-sale'))->assertUnprocessable();
        $this->assertSame($before, DB::table('finance_wallets')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('sales', 1);
        $attempt = $this->mutate($id, 'print/start', [], 'global-ready-print')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'global-ready-result');
        $this->travelTo(CarbonImmutable::now('Asia/Baghdad')->addDay()->startOfDay()->addMinute());
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-next-day-sale'))->assertCreated();
    }

    public function test_failed_print_and_manual_delivery_do_not_permanently_consume_future_print_capacity(): void
    {
        $f = $this->fixture();
        $policy = PrintPolicy::findOrFail(1);
        $policy->update(['policy' => array_replace($policy->policy, ['daily_cards' => 1])]);
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-failed-print-sale'))->assertCreated()->json('data.id');
        $attempt = $this->mutate($id, 'print/start', [], 'global-failed-print-start')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => false, 'reason' => 'Synthetic failed print'], 'global-failed-print-result');
        $this->travel(6)->seconds();
        $next = $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-delivered-sale'))->assertCreated()->json('data.id');
        $this->mutate($next, 'deliver', ['channel' => 'استلام مباشر', 'reference' => 'Synthetic manual delivery'], 'global-delivered-manual');
        $this->travel(6)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'global-after-delivery-sale'))->assertCreated();
    }

    public function test_print_rule_network_caps_cover_descendants_and_conflicting_scopes_are_rejected(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $rule = ['version' => 0, 'name' => 'Main network daily cap', 'active' => true, 'targets' => [['kind' => 'tree', 'id' => $f['main']->id]], 'policy' => $this->printPolicy(['daily_cards' => 2, 'daily_mode' => 'network']), 'idempotency_key' => 'network-print-rule'];
        $this->postJson('/api/v1/sales/print-rules', $rule)->assertCreated()->assertJsonPath('data.version', 1);
        $this->postJson('/api/v1/sales/print-rules', array_replace($rule, ['idempotency_key' => 'duplicate-network-rule']))->assertConflict();
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'network-two-sale', 2))->assertCreated();
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['sub']->id, 'to_account_id' => $f['pos']->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => '300.00', 'reference' => 'Network POS fund', 'idempotency_key' => 'network-pos-fund'])->assertCreated();
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'network-pos-over-cap'))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_print_policy_is_system_only_and_versioned_with_selected_product_precedence(): void
    {
        $f = $this->fixture();
        $input = ['version' => 1, 'policy' => $this->printPolicy(), 'settings' => ['provider_daily' => ['IQD' => '50000.00']], 'idempotency_key' => 'update-print-policy'];
        $this->putJson('/api/v1/sales/print-policy', $input)->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/sales/print-policy', $input)->assertOk()->assertJsonPath('data.settings.provider_daily.IQD', '50000.00')->assertJsonPath('data.version', 2);
        $this->putJson('/api/v1/sales/print-policy', array_replace($input, ['idempotency_key' => 'stale-print-policy']))->assertConflict();
        $rule = ['version' => 0, 'name' => 'Specific product max', 'active' => true, 'targets' => [['kind' => 'agent', 'id' => $f['sub']->id]], 'policy' => $this->printPolicy(['max_cards' => 1, 'daily_product_mode' => 'selected', 'daily_products' => [$f['product']->id]]), 'idempotency_key' => 'specific-product-rule'];
        $this->postJson('/api/v1/sales/print-rules', $rule)->assertCreated();
        $this->asPortalUser($f['seller']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.print_policy.max_cards', 1);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'rule-max-blocked', 2))->assertUnprocessable();
    }

    public function test_daily_print_pending_usage_is_reserved_and_successful_reprints_do_not_double_count(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'daily-print-one'))->assertCreated()->json('data.id');
        $this->travel(3)->seconds();
        $other = $this->postJson('/api/v1/sales', $this->input($f['product'], 'daily-print-two'))->assertCreated()->json('data.id');
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/sales/print-policy', ['version' => 1, 'policy' => $this->printPolicy(['daily_cards' => 1]), 'idempotency_key' => 'daily-print-global'])->assertOk();
        $this->asPortalUser($f['seller']);
        $attempt = $this->mutate($id, 'print/start', [], 'daily-print-start')['attempt_id'];
        $this->getJson('/api/v1/sales/products')->assertJsonPath('data.0.daily_print.pending', 1)->assertJsonPath('data.0.daily_print.remaining', 0);
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'daily-print-success');
        $this->travel(5)->seconds();
        $this->postJson('/api/v1/sales/'.$other.'/print/start', ['version' => 2, 'idempotency_key' => 'daily-print-over-limit'])->assertUnprocessable();
        $r = $this->postJson('/api/v1/sales/'.$id.'/reprint-request', ['version' => 4, 'reason' => 'Approved duplicate', 'idempotency_key' => 'daily-print-reprint-request'])->assertCreated()->json('data.id');
        $this->asPortalUser($f['owner']);
        $this->postJson('/api/v1/sales/reprint-requests/'.$r.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'daily-print-reprint-approval'])->assertOk();
        $this->asPortalUser($f['seller']);
        $attempt = $this->mutate($id, 'print/start', [], 'daily-print-approved-reprint')['attempt_id'];
        $this->mutate($id, 'print/result', ['attempt_id' => $attempt, 'success' => true], 'daily-print-reprint-result');
        $this->getJson('/api/v1/sales/products')->assertJsonPath('data.0.daily_print.used', 1)->assertJsonPath('data.0.daily_print.pending', 0);
    }

    public function test_higher_authority_sale_limit_cannot_be_bypassed_by_lower_more_permissive_limit(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $input = ['account_id' => $f['main']->id, 'product_id' => $f['product']->id, 'version' => 0, 'max_cards' => 1, 'daily_quantity' => null, 'daily_amount' => null, 'idempotency_key' => 'higher-sale-limit'];
        $this->putJson('/api/v1/sales/limits', $input)->assertOk()->assertJsonPath('data.version', 1);
        $this->asPortalUser($f['owner']);
        $input['account_id'] = $f['sub']->id;
        $input['max_cards'] = 10;
        $input['idempotency_key'] = 'lower-sale-limit';
        $this->putJson('/api/v1/sales/limits', $input)->assertOk();
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'bypass-higher-sale-limit', 2))->assertUnprocessable();
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sale_history_search_dates_pagination_foreign_scope_and_csv_omit_secrets(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'history-sale-001'))->assertCreated();
        $this->travel(3)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'history-sale-002'))->assertCreated();
        $this->getJson('/api/v1/sales?per_page=1&q='.urlencode($f['sub']->name))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/sales?to='.now('Asia/Baghdad')->toDateString())->assertOk()->assertJsonPath('meta.total', 2);
        $export = $this->get('/api/v1/sales/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('100.25', $export);
        foreach ($f['cards'] as $card) {
            $this->assertStringNotContainsString($card->secret['pin'], $export);
        }
        $other = $this->account(AccountType::MainAgent, $f['system']);
        $foreign = $this->userFor($other);
        $this->asPortalUser($foreign);
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/sales?account_id='.$f['sub']->id)->assertNotFound();
        $this->getJson('/api/v1/sales/1')->assertNotFound();
    }

    public function test_receipt_layout_keeps_all_blocks_and_main_agent_personalization_cannot_modify_company_content(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $data = ['version' => 0, 'layout' => ['header' => 'Server header', 'footer' => 'Server footer', 'color' => '#123456', 'display_order' => ReceiptDesign::BLOCKS, 'qr_enabled' => true, 'recharge_template' => '*101*{pin}#'], 'idempotency_key' => 'save-system-receipt-layout'];
        $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $data)->assertOk()->assertJsonPath('data.version', 1);
        $data['version'] = 1;
        $data['layout']['display_order'] = array_fill(0, 8, 'company');
        $data['idempotency_key'] = 'invalid-receipt-blocks';
        $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $data)->assertUnprocessable();
        $this->asPortalUser($f['owner']);
        $personal = ['account_id' => $f['main']->id, 'version' => 0, 'layout' => ['agent_text' => 'Main agent footer', 'agent_color' => '#abcdef'], 'idempotency_key' => 'agent-personal-receipt'];
        $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $personal)->assertOk();
        $personal['layout']['header'] = 'Changed company';
        $personal['version'] = 1;
        $personal['idempotency_key'] = 'agent-company-bypass';
        $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $personal)->assertUnprocessable();
        $this->asPortalUser($f['seller']);
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertOk()->assertJsonPath('data.design.header', 'Server header')->assertJsonPath('data.design.agent_text', 'Main agent footer')
            ->assertJsonPath('data.design.qr_enabled', true)->assertJsonPath('data.design.recharge_template', '*101*{pin}#');
        $storedLayouts = DB::table('sales_receipt_layouts')->pluck('layout')->implode('');
        $auditDetails = DB::table('audit_logs')->pluck('details')->implode('');
        foreach ($f['cards'] as $card) {
            $this->assertStringNotContainsString($card->secret['pin'], $storedLayouts);
            $this->assertStringNotContainsString($card->secret['pin'], $auditDetails);
        }
    }

    public function test_missing_pin_permission_cannot_issue_or_read_codes_but_can_reserve(): void
    {
        $f = $this->fixture();
        DB::table('membership_permissions')->insert(['membership_id' => $f['seller']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'data.pin')->value('id'), 'allowed' => false]);
        $this->asPortalUser($f['seller']);
        $input = $this->input($f['product'], 'pin-denied-sale');
        $this->postJson('/api/v1/sales', $input)->assertForbidden();
        $id = $this->postJson('/api/v1/sales/reservations', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/sales/reservations/'.$id.'/issue', ['version' => 1, 'idempotency_key' => 'pin-denied-reservation-issue'])->assertForbidden();
        $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertForbidden();
        $this->assertSame(0, DB::table('finance_transactions')->where('kind', 'sale')->count());
    }

    public function test_device_serial_minimum_version_and_session_revocation_block_pos_operations(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['sub']->id, 'to_account_id' => $f['pos']->id, 'service' => 'voucher', 'currency' => 'IQD', 'amount' => '300.00', 'reference' => 'Bind POS device', 'idempotency_key' => 'device-bound-pos-fund'])->assertCreated();
        $f['pos']->update(['serial' => 'Approved-Device-Serial']);
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales/device-session', ['serial' => 'Other-Device', 'app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'bound-device-mismatch'))->assertForbidden();
        $f['pos']->update(['device_lock_enabled' => false]);
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'unlocked-device'))->assertCreated();
        $f['pos']->update(['device_lock_enabled' => true]);
        $this->asPortalUser($f['point']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'locked-again-mismatch'))->assertForbidden();
        $this->postJson('/api/v1/sales/device-session', ['serial' => 'Approved-Device-Serial', 'app_version' => '0.9.0'])->assertOk();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'old-device-version'))->assertConflict();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/sales/device-session', ['serial' => 'Approved-Device-Serial', 'app_version' => '1.0.0'])->assertOk();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'bound-device-valid'))->assertCreated();
        DB::table('users')->where('id', $f['point']->id)->increment('session_version');
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'revoked-device-session'))->assertUnauthorized();
        $this->assertDatabaseCount('sales', 2);
    }

    public function test_posted_sale_transaction_link_cannot_be_rewritten_in_database(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $this->expectException(QueryException::class);
        DB::table('sales')->where('id', $id)->update(['transaction_id' => null]);
    }

    public function test_delivery_is_authorized_owned_audited_idempotent_and_never_refunds_voucher_credit(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/sales', $this->input($f['product']))->assertCreated()->json('data.id');
        $input = ['version' => 2, 'channel' => 'استلام مباشر', 'reference' => 'Customer receipt 1', 'idempotency_key' => 'direct-delivery-001'];
        $this->postJson('/api/v1/sales/'.$id.'/deliver', $input)->assertOk()->assertJsonPath('data.status', 'Delivered');
        $this->postJson('/api/v1/sales/'.$id.'/deliver', $input)->assertOk();
        $this->assertSame(1, StockCard::where('sale_id', $id)->where('status', 'Delivered')->count());
        $this->assertSame(1, DB::table('finance_transactions')->where('kind', 'sale')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'sell.deliver', 'subject_account_id' => $f['sub']->id]);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/sales/'.$id.'/deliver', array_replace($input, ['version' => 3, 'idempotency_key' => 'admin-delivery-impersonation']))->assertForbidden();
    }

    public function test_finance_stock_metrics_resolve_filtered_descendant_main_and_do_not_leak_to_seller_or_pos(): void
    {
        $f = $this->fixture();
        $this->getJson('/api/v1/finance/wallets?currency=IQD')->assertOk()->assertJsonPath('stock_metrics', null);
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/finance/wallets?currency=IQD&account_id='.$f['sub']->id)->assertOk()->assertJsonPath('stock_metrics.count', 12)->assertJsonPath('stock_metrics.value', '1203.00')->assertJsonPath('stock_metrics.cost', '1080.00');
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/finance/wallets?currency=IQD')->assertOk()->assertJsonPath('stock_metrics', null);
    }

    public function test_receipt_images_are_available_to_pos_without_catalog_management_and_never_expose_documents(): void
    {
        $f = $this->fixture();
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZf8AAAAASUVORK5CYII=');
        Storage::disk('local')->put('receipt-test/category.png', $png);
        $f['product']->update(['image_path' => 'receipt-test/category.png', 'image_mime' => 'image/png']);
        AccountAttachment::factory()->create(['account_id' => $f['main']->id, 'kind' => 'document', 'document_type' => 'national_card', 'storage_path' => 'receipt-test/category.png', 'uploaded_by' => $f['admin']->id]);
        $this->asPortalUser($f['point']);
        $this->get('/api/v1/sales/products/'.$f['product']->id.'/images/product')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/sales/products/'.$f['product']->id.'/images/agent?account_id='.$f['main']->id)->assertNotFound();
        $this->get('/api/v1/sales/products/'.$f['product']->id.'/images/document')->assertNotFound();
        $this->getJson('/api/v1/catalog/products')->assertForbidden();
    }

    public function test_agent_per_product_receipt_image_is_private_idempotent_removable_and_preserves_system_design(): void
    {
        $f = $this->fixture();
        Storage::fake('local');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZf8AAAAASUVORK5CYII=');
        $this->asPortalUser($f['owner']);
        $payload = ['account_id' => $f['main']->id, 'version' => 0, 'layout' => ['agent_text' => 'Personal image footer', 'agent_color' => '#abcdef', 'agent_image_removed' => false], 'idempotency_key' => 'agent-image-save-001'];
        $input = ['_method' => 'PUT', 'payload' => json_encode($payload), 'image' => UploadedFile::fake()->createWithContent('agent.png', $png)];
        $this->post('/api/v1/sales/receipt-layouts/'.$f['product']->id, $input, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 1)->assertJsonMissingPath('data.layout.agent_image_path');
        $input['image'] = UploadedFile::fake()->createWithContent('agent.png', $png);
        $this->post('/api/v1/sales/receipt-layouts/'.$f['product']->id, $input, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('receipt-agent-images'));
        $this->asPortalUser($f['point']);
        $url = '/api/v1/sales/products/'.$f['product']->id.'/images/agent?account_id='.$f['main']->id;
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/api/v1/sales/products/'.$f['product']->id.'/images/agent?account_id='.$f['system']->id)->assertForbidden();
        $this->asPortalUser($f['owner']);
        $payload['version'] = 1;
        $payload['layout']['agent_image_removed'] = true;
        $payload['idempotency_key'] = 'agent-image-remove-001';
        $this->putJson('/api/v1/sales/receipt-layouts/'.$f['product']->id, $payload)->assertOk();
        $this->getJson('/api/v1/sales/receipt-layouts/'.$f['product']->id)->assertOk()->assertJsonPath('data.agent_image', null)->assertJsonPath('data.header', 'Original header');
        $this->asPortalUser($f['point']);
        $this->get($url)->assertNotFound();
    }

    public function test_receipt_image_storage_rolls_back_when_audit_cannot_be_saved(): void
    {
        $f = $this->fixture();
        Storage::fake('local');
        $this->asPortalUser($f['owner']);
        $logger = $this->mock(AuditLogger::class);
        $logger->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jZf8AAAAASUVORK5CYII=');
        $payload = ['account_id' => $f['main']->id, 'version' => 0, 'layout' => ['agent_text' => 'Rejected upload'], 'idempotency_key' => 'agent-image-rollback-001'];
        $this->post('/api/v1/sales/receipt-layouts/'.$f['product']->id, ['_method' => 'PUT', 'payload' => json_encode($payload), 'image' => UploadedFile::fake()->createWithContent('agent.png', $png)], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseCount('sales_receipt_layouts', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('receipt-agent-images'));
        $this->assertDatabaseMissing('finance_operations', ['idempotency_key' => 'agent-image-rollback-001']);
    }

    public function test_configuration_options_exposes_only_scoped_configuration_without_costs_pin_or_pos_management(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/sales/configuration-options')->assertOk()->assertJsonPath('data.products.0.id', $f['product']->id)->assertJsonMissingPath('data.products.0.cost')->assertJsonMissingPath('data.products.0.price')->assertJsonMissingPath('data.products.0.pin');
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/sales/configuration-options')->assertForbidden();
        $this->asPortalUser($f['seller']);
        $id = $this->postJson('/api/v1/sales', $this->input($f['product'], 'receipt-print-context'))->assertCreated()->json('data.id');
        $this->getJson('/api/v1/sales/'.$id.'/receipt')->assertOk()->assertJsonPath('data.print_policy.max_cards', 10)->assertJsonPath('data.print_policy.failed_retries', 2)->assertJsonPath('data.print_wait_seconds', 0);
    }

    public function test_profit_denial_removes_profit_values_from_json_and_export_without_hiding_authorized_costs(): void
    {
        $f = $this->fixture();
        DB::table('membership_permissions')->insert(['membership_id' => $f['seller']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'data.profit')->value('id'), 'allowed' => false]);
        $this->asPortalUser($f['seller']);
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'profit-hidden-sale'))->assertCreated()->assertJsonMissingPath('data.pos_profit')->assertJsonMissingPath('data.agent_margin')->assertJsonPath('data.cost', '90.00');
        $this->getJson('/api/v1/sales')->assertOk()->assertJsonMissingPath('data.0.pos_profit')->assertJsonMissingPath('data.0.agent_margin');
        $csv = $this->get('/api/v1/sales/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('تكلفة التحميل', $csv);
        $this->assertStringNotContainsString('هامش الوكيل', $csv);
    }

    public function test_design_and_exception_options_do_not_require_unrelated_sales_view_permission(): void
    {
        $f = $this->fixture();
        DB::table('membership_permissions')->insert(['membership_id' => $f['owner']->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'sales.view')->value('id'), 'allowed' => false]);
        $this->asPortalUser($f['owner']);
        $this->getJson('/api/v1/sales/configuration-options')->assertOk()->assertJsonPath('data.products.0.id', $f['product']->id);
        $this->getJson('/api/v1/sales/options')->assertOk()->assertJsonPath('data.seller_account_id', null)->assertJsonCount(0, 'data.products');
        $this->getJson('/api/v1/sales')->assertForbidden();
    }

    public function test_sale_status_summary_uses_all_authorized_matching_records_and_ignores_selected_status_for_tabs(): void
    {
        $f = $this->fixture();
        $this->freezeTime();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'summary-first-sale'))->assertCreated();
        $this->travel(4)->seconds();
        $this->postJson('/api/v1/sales', $this->input($f['product'], 'summary-second-sale'))->assertCreated();
        $this->getJson('/api/v1/sales/summary?status=Printed')->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.status_counts.Print Requested', 2);
        $this->getJson('/api/v1/sales/summary?currency=USD')->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->getJson('/api/v1/sales/summary?account_id='.$f['main']->id)->assertNotFound();
        $this->asPortalUser($f['point']);
        $this->getJson('/api/v1/sales/summary')->assertOk()->assertJsonPath('data.total', 0);
    }
}
