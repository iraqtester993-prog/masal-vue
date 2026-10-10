<?php

namespace Tests\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\Finance\PriceRequest;
use App\Models\OrderSource;
use App\Models\Stock\StockBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait CreatesStock
{
    use CreatesAccounts;

    /** @return array{system:Account,main:Account,admin:User,agent:User,product:CatalogProduct,source:OrderSource} */
    protected function stockFixture(): array
    {
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00', 'Asia/Baghdad'));
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $product = CatalogProduct::factory()->create(['import_codes' => ['TEST-5K'], 'minimum_price' => '4000.00']);
        $source = OrderSource::factory()->create(['network_account_id' => $main->id, 'provider_id' => $product->provider_id]);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $main->id, 'authority_account_id' => $system->id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $product->id]);
        $priceRequest = PriceRequest::factory()->create(['account_id' => $main->id, 'creator_id' => $admin->id, 'status' => 'approved']);
        DB::table('finance_prices')->insert(['account_id' => $main->id, 'product_id' => $product->id, 'request_id' => $priceRequest->id, 'currency' => 'IQD', 'price_minor' => 450025, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return compact('system', 'main', 'admin', 'agent', 'product', 'source');
    }

    protected function stockDraft(array $fixture, int $quantity = 3, string $suffix = 'first'): array
    {
        return ['order_key' => 'order-'.$suffix.'-001', 'account_id' => $fixture['main']->id, 'provider_id' => $fixture['product']->provider_id, 'source_id' => $fixture['source']->id, 'city' => 'بغداد', 'category_count' => 1, 'lines' => [['key' => 'line-one', 'name' => 'supplier.csv', 'category_code' => 'EVS-TEST-5K', 'product_id' => $fixture['product']->id, 'declared_count' => $quantity, 'cost' => '4000.10', 'expenses' => '0.03', 'rows' => array_map(fn (int $i): array => ['serial' => 'SERIAL-'.$suffix.'-'.$i, 'pin' => 'SECRET-PIN-'.$suffix.'-'.$i, 'expiry' => '2031-01-01'], range(1, $quantity))]]];
    }

    protected function submitStock(array $fixture, ?array $draft = null, string $key = 'submit-stock-001'): int
    {
        $this->asPortalUser($fixture['agent']);
        $preview = $this->postJson('/api/v1/stock/orders/preview', $draft ?? $this->stockDraft($fixture))->assertOk()->json('data');
        $input = $preview['draft'] + ['preview_hash' => $preview['preview_hash'], 'exclude_rejected' => true, 'idempotency_key' => $key];

        return $this->postJson('/api/v1/stock/orders', $input)->assertSuccessful()->json('data.id');
    }

    protected function approvedStock(array $fixture, ?array $draft = null): StockBatch
    {
        $orderId = $this->submitStock($fixture, $draft);
        $this->asPortalUser($fixture['admin']);
        $this->postJson('/api/v1/stock/orders/'.$orderId.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'approve-stock-001'])->assertOk()->assertJsonPath('data.status', 'approved');

        return StockBatch::where('order_id', $orderId)->firstOrFail();
    }

    protected function voucherBalance(Account $account): int
    {
        return (int) DB::table('finance_wallets')->where('account_id', $account->id)->where('service', 'voucher')->where('currency', 'IQD')->where('kind', 'account')->value('balance_minor');
    }
}
