<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\TopupGrant;
use App\Models\Sales\PrintPolicy;
use App\Models\Sales\PrintRule;
use App\Models\Sales\Sale;
use App\Services\Digital\DigitalAccess;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Digital\TopupAccounting;
use App\Services\Sales\SalesPolicies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class BackendReadPerformanceTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function tree(): array
    {
        Http::preventStrayRequests();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $point = $this->account(AccountType::Pos, $main);

        return compact('system', 'main', 'point');
    }

    public function test_connection_pages_share_account_scope_and_provider_loading_as_the_page_grows(): void
    {
        $f = $this->tree();
        $this->asPortalUser($this->userFor($f['system']));
        foreach (range(1, 25) as $index) {
            $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id]);
            DigitalOffer::factory()->create(['connection_id' => $connection->id]);
        }
        $counts = [];
        foreach ([1, 25] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->getJson('/api/v1/digital/connections?per_page='.$size)->assertOk()->assertJsonCount($size, 'data');
            $queries = collect(DB::getQueryLog())->pluck('query');
            $counts[] = $queries->count();
            $this->assertSame(1, $queries->filter(fn ($sql): bool => str_starts_with($sql, 'select "id" from "accounts"'))->count());
            $this->assertLessThanOrEqual(2, $queries->filter(fn ($sql): bool => str_starts_with($sql, 'select * from "catalog_providers"'))->count());
            DB::disableQueryLog();
        }
        $this->assertLessThanOrEqual($counts[0] + 4, $counts[1]);
        Http::assertNothingSent();
    }

    private function catalog(array $f, int $count): array
    {
        $products = CatalogProduct::factory()->count($count)->create();
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $f['main']->id, 'authority_account_id' => $f['system']->id]);
        DB::table('catalog_rule_products')->insert($products->map(fn ($product): array => ['rule_id' => $rule, 'product_id' => $product->id])->all());

        return [$products, $rule];
    }

    private function printedSale(array $f, CatalogProduct $product, int $quantity, array $state = []): Sale
    {
        return Sale::factory()->create(array_replace(['main_account_id' => $f['main']->id, 'account_id' => $f['point']->id, 'creator_id' => $f['user']->id, 'product_id' => $product->id, 'quantity' => $quantity, 'total_minor' => $quantity * 10000, 'retail_total_minor' => $quantity * 11000, 'credit_minor' => $quantity * 10000, 'cost_minor' => $quantity * 9000, 'load_cost_minor' => $quantity * 10000], $state));
    }

    public function test_selected_product_print_usage_is_batched_and_the_next_request_sees_new_prints(): void
    {
        $f = $this->tree();
        $f['user'] = $this->userFor($f['point']);
        [$products, $rule] = $this->catalog($f, 25);
        $policy = PrintPolicy::findOrFail(1);
        $policy->update(['policy' => array_replace($policy->policy, ['daily_product_mode' => 'selected', 'daily_products' => $products->modelKeys(), 'daily_cards' => 20])]);
        [$start, $end] = app(SalesPolicies::class)->day();
        $first = $products->first();
        $this->printedSale($f, $first, 3, ['first_printed_at' => $start->addHour()]);
        $this->printedSale($f, $first, 9, ['first_printed_at' => $start->subSecond()]);
        $this->printedSale($f, $first, 2, ['print_pending' => true, 'created_at' => $start->subDay()]);
        $this->asPortalUser($f['user']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonCount(25, 'data');
        $aggregateQueries = collect(DB::getQueryLog())->filter(fn ($query): bool => str_contains(strtolower($query['query']), 'sum(') && str_contains($query['query'], '"sales"'));
        DB::disableQueryLog();
        $this->assertCount(1, $aggregateQueries);
        $this->assertSame(['limit' => 20, 'used' => 3, 'pending' => 2, 'remaining' => 15, 'day' => $start->setTimezone('Asia/Baghdad')->toDateString(), 'mode' => 'account'], $response->json('data.0.daily_print'));
        $this->assertSame(0, $response->json('data.24.daily_print.used'));
        $this->printedSale($f, $first, 4, ['first_printed_at' => $start->addHours(2)]);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.daily_print.used', 7)->assertJsonPath('data.0.daily_print.remaining', 11);
        $this->assertDatabaseCount('finance_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_network_and_selected_rules_keep_global_and_product_totals_separate(): void
    {
        $f = $this->tree();
        $f['user'] = $this->userFor($f['point']);
        [$products] = $this->catalog($f, 2);
        $policy = PrintPolicy::findOrFail(1);
        $base = array_replace($policy->policy, ['daily_mode' => 'network', 'daily_cards' => 20]);
        $policy->update(['policy' => $base]);
        PrintRule::factory()->create(['active' => true, 'targets' => [['kind' => 'pos', 'id' => $f['point']->id]], 'policy' => array_replace($base, ['daily_product_mode' => 'selected', 'daily_products' => [$products[0]->id]])]);
        [$start] = app(SalesPolicies::class)->day();
        $this->printedSale($f, $products[0], 3, ['first_printed_at' => $start->addHour()]);
        $this->printedSale($f, $products[1], 4, ['first_printed_at' => $start->addHour()]);
        $otherPoint = $this->account(AccountType::Pos, $f['main']);
        $this->printedSale($f, $products[0], 2, ['account_id' => $otherPoint->id, 'print_pending' => true]);
        $this->asPortalUser($f['user']);
        $this->getJson('/api/v1/sales/products')->assertOk()->assertJsonPath('data.0.daily_print.used', 3)->assertJsonPath('data.0.daily_print.pending', 2)
            ->assertJsonPath('data.1.daily_print.used', 7)->assertJsonPath('data.1.daily_print.pending', 2)->assertJsonPath('data.1.daily_print.remaining', 11);
    }

    public function test_snapshot_validation_keeps_older_valid_offers_without_materializing_snapshot_history(): void
    {
        $f = $this->tree();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['system']->id]);
        $valid = CatalogSnapshot::factory()->create(['connection_id' => $connection->id]);
        $other = CatalogSnapshot::factory()->create(['connection_id' => $connection->id, 'credential_hash' => DigitalConfiguration::credentialHash('rotated-token')]);
        $goodOffer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $valid->id]);
        $badOffer = DigitalOffer::factory()->create(['connection_id' => $connection->id, 'catalog_snapshot_id' => $other->id, 'remote_id' => '72', 'remote_key' => hash('sha256', '72:')]);
        foreach (range(1, 100) as $index) {
            CatalogSnapshot::factory()->create(['connection_id' => $connection->id]);
        }
        $request = Request::create('/api/v1/digital/offers', 'GET');
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $offers = app(DigitalAccess::class)->offers($connection->fresh(), $f['system']);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([$goodOffer->id], $offers->pluck('id')->all());
        $verified = $queries->first(fn ($row): bool => str_starts_with($row['query'], 'select "id" from "digital_catalog_snapshots"'));
        $this->assertNotNull($verified);
        $this->assertStringContainsString('"id" in (', $verified['query']);
        $this->assertLessThanOrEqual(5, count($verified['bindings']));
        $connection->update(['credential' => 'rotated-token']);
        $request->attributes->set('masal.read_cache', []);
        $this->assertSame([$badOffer->id], app(DigitalAccess::class)->offers($connection->fresh(), $f['system'])->pluck('id')->all());
    }

    public function test_topup_free_amounts_are_batched_for_get_only_and_writes_always_read_fresh_allocations(): void
    {
        $f = $this->tree();
        $connections = DigitalConnection::factory()->count(10)->create(['account_id' => $f['system']->id, 'provider' => 'topup', 'provider_id' => null, 'company_balance_minor' => 9400000]);
        $grant = TopupGrant::factory()->create(['target_account_id' => $f['main']->id, 'main_account_id' => $f['main']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $connections[0]->id, 'balance_minor' => 3000000]);
        $request = Request::create('/api/v1/digital/connections', 'GET');
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $accounting = app(TopupAccounting::class);
        $accounting->primeFree($connections);
        foreach ($connections as $connection) {
            $this->assertSame($connection->id === $connections[0]->id ? 6400000 : 9400000, $accounting->free($connection));
        }
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $grant->update(['balance_minor' => 4000000]);
        $write = Request::create('/api/v1/topup/distribution', 'POST');
        $write->attributes->set('masal.read_cache', $request->attributes->get('masal.read_cache'));
        $this->app->instance('request', $write);
        $this->assertSame(5400000, $accounting->free($connections[0]));
        $grant->update(['balance_minor' => 5000000]);
        $this->assertSame(4400000, $accounting->free($connections[0]));
        $request->attributes->set('masal.read_cache', []);
        $this->app->instance('request', $request);
        $this->assertSame(4400000, $accounting->free($connections[0]));
        Http::assertNothingSent();
    }
}
