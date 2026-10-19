<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\Invoice;
use App\Models\Finance\Wallet;
use App\Services\Digital\DigitalOperations;
use App\Services\Finance\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class CentralTopupAccountingTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private string $inventory = '100000';

    private bool $eligible = true;

    private bool $unknown = false;

    private int $purchases = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.purchases_enabled' => true]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/inventory')) {
                return Http::response($this->inventory, 200, ['Content-Type' => 'application/json']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1]]]);
            }
            if (str_ends_with($request->url(), '/checkEligibility')) {
                return Http::response(['status' => $this->eligible]);
            }
            $this->purchases++;
            $this->inventory = '94000';

            return Http::response($this->unknown ? ['status' => 'unknown'] : ['id' => 'central-purchase-'.$this->purchases, 'status' => 'Succeed']);
        });
    }

    private function setupCentral(): array
    {
        $f = $this->supportFixture();
        $f['pos']->update(['device_lock_enabled' => false]);
        $this->asPortalUser($f['admin']);
        $id = $this->postJson('/api/v1/digital/connections', ['provider' => 'topup', 'account_id' => $f['system']->id, 'version' => 0, 'active' => true, 'credential' => 'central-private-token', 'offers' => [], 'idempotency_key' => 'central-connect-001'])->assertCreated()->json('data.id');
        $synchronized = $this->postJson('/api/v1/digital/connections/'.$id.'/sync', ['version' => 1])->assertOk();
        $this->assertSame('ok', $synchronized->json('data.sync.catalog'), $synchronized->json('data.sync.catalog_message'));
        $category = TopupCategory::where('connection_id', $id)->firstOrFail();
        $f += ['connection' => DigitalConnection::findOrFail($id), 'category' => $category];
        $this->allocate($f, $f['main']->id, '40000.00');
        foreach ([['agent', 'sub'], ['subUser', 'branch'], ['branchUser', 'pos']] as [$user, $target]) {
            $this->asPortalUser($f[$user]);
            $this->putJson('/api/v1/topup/distribution/'.$f[$target]->id, ['version' => 0, 'category_ids' => [$category->id], 'active' => true, 'idempotency_key' => 'category-grant-'.$target])->assertOk();
        }

        return $f;
    }

    private function allocate(array $f, int $target, string $balance, int $version = 0): void
    {
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/topup/distribution/'.$target, ['version' => $version, 'connection_id' => $f['connection']->id, 'allocation_balance' => $balance, 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'central-allocate-'.$target.'-'.$version])->assertOk();
    }

    private function price(array $f, string $price): void
    {
        $this->asPortalUser($f['agent']);
        $grant = TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail();
        $this->putJson('/api/v1/topup/prices', ['version' => $grant->version, 'prices' => [['category_id' => $f['category']->id, 'price' => $price]], 'idempotency_key' => 'agent-price-'.$grant->version])->assertOk();
    }

    private function saleInput(array $f, string $key = 'central-sale-001'): array
    {
        if (! Wallet::where('account_id', $f['pos']->id)->where('service', 'topup')->where('kind', 'account')->exists()) {
            $mainWallet = Wallet::where('account_id', $f['main']->id)->where('service', 'topup')->where('kind', 'account')->firstOrFail();
            $funding = min(1000000, $mainWallet->balance_minor - $mainWallet->held_minor);
            if ($funding > 0) {
                $this->asPortalUser($f['agent']);
                $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['main']->id, 'to_account_id' => $f['pos']->id, 'service' => 'topup', 'currency' => 'IQD', 'amount' => Money::decimal($funding), 'reference' => 'Central funded point', 'idempotency_key' => 'central-point-funding-'.$f['pos']->id])->assertCreated();
            }
        }
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonPath('data.execution_ready', true)->assertJsonMissingPath('data.categories.0.admin_price')->json('data.categories.0.offer');
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());

        return ['request_id' => $key, 'connection_id' => $f['connection']->id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'];
    }

    public function test_confirmed_sale_debits_funded_pos_and_records_admin_price_and_nonspendable_margin_once(): void
    {
        $f = $this->setupCentral();
        $this->asPortalUser($f['admin']);
        $offer = $f['connection']->offers()->firstOrFail();
        $this->putJson('/api/v1/digital/connections/'.$f['connection']->id, ['provider' => 'topup', 'account_id' => $f['system']->id, 'version' => $f['connection']->fresh()->version, 'active' => true, 'offers' => [['id' => $offer->id, 'product_id' => $offer->product_id, 'remote_id' => $offer->remote_id, 'retail' => '6200.00', 'active' => true]], 'idempotency_key' => 'set-admin-base-6200'])->assertOk();
        $this->price($f, '6500.00');
        $input = $this->saleInput($f);
        $id = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.retail', '6500.00')->json('data.id');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, $this->purchases);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 3380000, 'spent_minor' => 620000, 'held_minor' => 0]);
        $this->assertDatabaseHas('finance_invoices', ['source_type' => 'digital_topup', 'source_id' => $id, 'account_id' => $f['pos']->id, 'creditor_account_id' => $f['main']->id, 'amount_minor' => 650000]);
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertDatabaseCount('finance_wallets', 4);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'service' => 'topup', 'kind' => 'account', 'balance_minor' => 3000000]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['pos']->id, 'service' => 'topup', 'kind' => 'account', 'balance_minor' => 350000]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'service' => 'topup', 'kind' => 'external', 'balance_minor' => 30000]);
        $this->assertDatabaseCount('finance_entries', 7);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/orders/'.$id)->assertOk()->assertJsonPath('data.admin_price', '6200.00')->assertJsonPath('data.agent_markup', '300.00');
        $this->price($f, '7000.00');
        $this->assertSame(650000, DigitalOrder::findOrFail($id)->quoted_retail_minor);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/digital/orders?main_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.0.id', $id);
    }

    public function test_collection_credits_cash_to_creditor_without_deducting_topup_again(): void
    {
        $f = $this->setupCentral();
        $this->price($f, '6500.00');
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated();
        $invoice = Invoice::where('source_type', 'digital_topup')->firstOrFail();
        $this->supportGrant($f['agent'], 'invoices.settle');
        $this->asPortalUser($f['agent']);
        $payload = ['version' => 1, 'amount' => '2500.00', 'reference' => 'cash receipt 1', 'idempotency_key' => 'central-collect-001'];
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $payload)->assertOk()->assertJsonPath('data.remaining', '4000.00');
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $payload)->assertOk();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'service' => 'cash', 'kind' => 'account', 'balance_minor' => 250000]);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 3400000, 'spent_minor' => 600000]);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $f['pos']->id, 'service' => 'cash']);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['pos']->id, 'service' => 'topup', 'balance_minor' => 350000, 'held_minor' => 0]);
        $remaining = ['version' => 2, 'amount' => '4000.00', 'reference' => 'cash receipt 2', 'idempotency_key' => 'central-collect-002'];
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $remaining)->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.remaining', '0.00');
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $remaining)->assertOk();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['main']->id, 'service' => 'cash', 'kind' => 'account', 'balance_minor' => 650000]);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 3400000, 'spent_minor' => 600000]);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', ['version' => 3, 'amount' => '1.00', 'reference' => 'excess collection', 'idempotency_key' => 'central-collect-excess'])->assertUnprocessable();
        $this->assertSame(0, Artisan::call('topup:audit-accounting'), Artisan::output());
    }

    public function test_same_central_token_can_supply_two_agents_with_separate_limits(): void
    {
        $f = $this->setupCentral();
        $this->allocate($f, $f['foreign']->id, '60000.00');
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['foreign']->id)->assertOk()->assertJsonPath('data.connections.0.available', '0.00');
        $this->putJson('/api/v1/topup/distribution/'.$f['foreign']->id, ['version' => 1, 'connection_id' => $f['connection']->id, 'allocation_balance' => '60001.00', 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'overallocate-central'])->assertUnprocessable();
        $this->assertDatabaseCount('digital_connections', 1);
        $this->asPortalUser($f['foreignUser']);
        $this->getJson('/api/v1/topup/distribution')->assertOk()->assertJsonPath('data.allocation.balance', '60000.00');
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/sync', ['version' => 2])->assertForbidden();
    }

    public function test_unknown_result_keeps_reservation_and_cannot_be_reclaimed_or_rebought(): void
    {
        $f = $this->setupCentral();
        $this->unknown = true;
        $input = $this->saleInput($f);
        $id = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->json('data.id');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated();
        $this->assertSame(1, $this->purchases);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'held_minor' => 600000, 'balance_minor' => 4000000]);
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['version' => 1, 'connection_id' => $f['connection']->id, 'allocation_balance' => '5000.00', 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'reclaim-held-central'])->assertUnprocessable();
    }

    public function test_failed_sale_releases_hold_and_creates_no_receivable(): void
    {
        $f = $this->setupCentral();
        $this->eligible = false;
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'held_minor' => 0, 'balance_minor' => 4000000, 'spent_minor' => 0]);
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(0, $this->purchases);
        $this->assertSame(0, Artisan::call('topup:audit-accounting'), Artisan::output());
    }

    public function test_resync_keeps_allocations_and_underpriced_agent_sale_is_denied(): void
    {
        $f = $this->setupCentral();
        $this->asPortalUser($f['agent']);
        $this->putJson('/api/v1/topup/prices', ['version' => 1, 'prices' => [['category_id' => $f['category']->id, 'price' => '5999.00']], 'idempotency_key' => 'underprice-central'])->assertUnprocessable();
        $this->asPortalUser($f['admin']);
        $this->inventory = '200000';
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/sync', ['version' => $f['connection']->fresh()->version])->assertOk();
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 4000000]);
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.connections.0.available', '160000.00');
        $this->allocate($f, $f['main']->id, '5000.00', 1);
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertUnprocessable();
        $this->assertSame(0, $this->purchases);
        $this->assertDatabaseCount('digital_orders', 0);
    }

    public function test_legacy_token_migration_keeps_connection_identity_and_allocates_only_known_balance(): void
    {
        $f = $this->supportFixture();
        $connection = DigitalConnection::factory()->create(['account_id' => $f['main']->id, 'provider' => 'topup', 'company_balance_minor' => 9400000]);
        $unknown = DigitalConnection::factory()->create(['account_id' => $f['foreign']->id, 'provider' => 'topup', 'company_balance_minor' => null]);
        TopupGrant::factory()->create(['main_account_id' => $f['main']->id, 'from_account_id' => $f['system']->id, 'target_account_id' => $f['main']->id, 'category_ids' => [49], 'active' => true, 'version' => 4]);
        $encrypted = $connection->getRawOriginal('credential');
        $migration = require database_path('migrations/2026_10_10_131003_add_central_topup_allocations.php');
        $migration->centralizeConnections();
        $this->assertDatabaseHas('digital_connections', ['id' => $connection->id, 'account_id' => $f['system']->id, 'credential' => $encrypted]);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'connection_id' => $connection->id, 'balance_minor' => 9400000, 'version' => 5, 'active' => true]);
        $this->assertSame([49], TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail()->category_ids);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['foreign']->id, 'connection_id' => $unknown->id, 'balance_minor' => 0, 'active' => false]);
    }

    public function test_two_points_share_the_main_allocation_and_pending_hold_blocks_overspending(): void
    {
        $f = $this->setupCentral();
        $this->allocate($f, $f['main']->id, '6000.00', 1);
        $this->unknown = true;
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated()->assertJsonPath('data.status', 'review');
        $second = $this->account(AccountType::Pos, $f['main']);
        $second->update(['device_lock_enabled' => false]);
        $secondUser = $this->userFor($second);
        $this->asPortalUser($f['agent']);
        $this->putJson('/api/v1/topup/distribution/'.$second->id, ['version' => 0, 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'second-pos-categories'])->assertOk();
        $input = $this->saleInput(array_replace($f, ['pos' => $second, 'posUser' => $secondUser]), 'second-pos-order');
        $this->postJson('/api/v1/digital/orders', $input)->assertUnprocessable();
        $this->assertSame(1, $this->purchases);
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('finance_invoices', 0);
    }

    public function test_saved_success_reconciliation_accounts_once_without_another_provider_purchase(): void
    {
        $f = $this->setupCentral();
        $input = $this->saleInput($f);
        $request = Request::create('/api/v1/digital/orders', 'POST');
        $request->attributes->set('portal', 'pos');
        $request->setLaravelSession(app('session')->driver());
        $order = app(DigitalOperations::class)->begin($f['posUser']->fresh(), $input, $request);
        $order->update(['provider_purchase_started_at' => now(), 'provider_purchase_response' => ['status' => 'Succeed', 'id' => 'saved-central-result']]);
        $payload = ['version' => $order->version, 'idempotency_key' => 'saved-central-verify'];
        $this->postJson('/api/v1/digital/orders/'.$order->id.'/verify', $payload)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->postJson('/api/v1/digital/orders/'.$order->id.'/verify', $payload)->assertOk();
        $this->assertDatabaseCount('finance_invoices', 1);
        $this->assertSame(0, $this->purchases);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 3400000, 'held_minor' => 0, 'spent_minor' => 600000]);
    }

    public function test_central_token_cannot_be_replaced_while_agent_funds_remain_allocated(): void
    {
        $f = $this->setupCentral();
        $this->asPortalUser($f['admin']);
        $payload = ['provider' => 'topup', 'account_id' => $f['system']->id, 'version' => $f['connection']->fresh()->version, 'active' => true, 'credential' => 'replacement-central-token', 'offers' => [], 'idempotency_key' => 'rotate-central-funded'];
        $this->putJson('/api/v1/digital/connections/'.$f['connection']->id, $payload)->assertConflict();
        $this->assertSame('central-private-token', $f['connection']->fresh()->credential);
        $this->allocate($f, $f['main']->id, '0.00', 1);
        $payload['idempotency_key'] = 'rotate-central-reclaimed';
        $this->putJson('/api/v1/digital/connections/'.$f['connection']->id, $payload)->assertOk()->assertJsonPath('data.company_balance', null);
    }

    public function test_read_only_audit_accepts_sale_and_collection_and_detects_a_hold_mismatch(): void
    {
        $f = $this->setupCentral();
        $this->price($f, '6500.00');
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated();
        $invoice = Invoice::where('source_type', 'digital_topup')->firstOrFail();
        $this->supportGrant($f['agent'], 'invoices.settle');
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', ['version' => 1, 'amount' => '2500.00', 'reference' => 'audit collection', 'idempotency_key' => 'audit-collect-001'])->assertOk();
        $grant = TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail();
        $before = $grant->getAttributes();
        $this->assertSame(0, Artisan::call('topup:audit-accounting'), Artisan::output());
        $this->assertSame($before, $grant->fresh()->getAttributes());
        $this->assertDatabaseCount('finance_invoices', 1);
        $grant->update(['held_minor' => 100]);
        $this->artisan('topup:audit-accounting')->expectsOutputToContain('grant_held')->assertFailed();
        $this->assertSame(100, $grant->fresh()->held_minor);
    }

    public function test_read_only_audit_accepts_unknown_and_failed_results_without_invoices(): void
    {
        $f = $this->setupCentral();
        $this->unknown = true;
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated();
        $this->assertSame(0, Artisan::call('topup:audit-accounting'), Artisan::output());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertSame(1, $this->purchases);
    }

    public function test_scoped_allocator_cannot_recover_change_categories_or_reach_another_agent(): void
    {
        $f = $this->setupCentral();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'topup.view', 'topup.allocate']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['main']->id)->assertOk()->assertJsonPath('data.can_allocate', true)->assertJsonPath('data.can_assign', false)->assertJsonPath('data.grant.balance', '40000.00')->assertJsonMissingPath('data.connection.company_balance')->assertJsonPath('data.connections.0.balance', null);
        $input = ['version' => 1, 'connection_id' => $f['connection']->id, 'allocation_balance' => '45000.00', 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'scoped-allocate-increase'];
        $path = '/api/v1/topup/distribution/'.$f['main']->id;
        $this->putJson($path, $input)->assertOk()->assertJsonPath('data.balance', '45000.00');
        $before = DB::table('finance_transactions')->count();
        $this->putJson($path, array_replace($input, ['version' => 2, 'allocation_balance' => '40000.00', 'idempotency_key' => 'scoped-allocate-deny-decrease']))->assertForbidden();
        $this->putJson($path, array_replace($input, ['version' => 2, 'category_ids' => [], 'idempotency_key' => 'scoped-allocate-deny-categories']))->assertForbidden();
        $this->putJson('/api/v1/topup/distribution/'.$f['foreign']->id, array_replace($input, ['version' => 0, 'idempotency_key' => 'scoped-allocate-deny-foreign']))->assertNotFound();
        $this->assertSame($before, DB::table('finance_transactions')->count());
        $this->assertSame(4500000, TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail()->balance_minor);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_scoped_recovery_cannot_allocate_more_funds(): void
    {
        $f = $this->setupCentral();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'topup.view', 'topup.recover']);
        $this->asPortalUser($employee);
        $input = ['version' => 1, 'connection_id' => $f['connection']->id, 'allocation_balance' => '35000.00', 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'scoped-recovery-decrease'];
        $path = '/api/v1/topup/distribution/'.$f['main']->id;
        $this->putJson($path, $input)->assertOk()->assertJsonPath('data.balance', '35000.00');
        $before = DB::table('finance_transactions')->count();
        $this->putJson($path, array_replace($input, ['version' => 2, 'allocation_balance' => '40000.00', 'idempotency_key' => 'scoped-recovery-deny-increase']))->assertForbidden();
        $this->assertSame($before, DB::table('finance_transactions')->count());
        $this->assertSame(3500000, TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail()->balance_minor);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_category_assignment_permission_does_not_change_allocated_funds(): void
    {
        $f = $this->setupCentral();
        $employee = $this->supportEmployee($f['system'], $f['main'], ['account.view', 'topup.view', 'topup.assign']);
        $this->asPortalUser($employee);
        $before = [];
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['version' => 1, 'connection_id' => $f['connection']->id, 'category_ids' => [], 'active' => true, 'idempotency_key' => 'category-only-assignment'])->assertOk()->assertJsonPath('data.balance', '40000.00');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_prices_can_be_delegated_without_category_assignment_or_balance_rights(): void
    {
        $f = $this->setupCentral();
        $employee = $this->supportEmployee($f['main'], $f['main'], ['account.view', 'topup.view', 'topup.prices']);
        $this->asPortalUser($employee);
        $before = DB::table('finance_transactions')->count();
        $this->putJson('/api/v1/topup/prices', ['version' => 1, 'prices' => [['category_id' => $f['category']->id, 'price' => '6500.00']], 'idempotency_key' => 'delegated-topup-price'])->assertOk();
        $this->putJson('/api/v1/topup/distribution/'.$f['sub']->id, ['version' => 1, 'category_ids' => [], 'active' => true, 'idempotency_key' => 'price-deny-category-assignment'])->assertForbidden();
        $this->assertSame($before, DB::table('finance_transactions')->count());
        $this->assertSame(4000000, TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail()->balance_minor);
    }

    public function test_permission_upgrade_preserves_nonzero_topup_balances_and_completed_provider_orders(): void
    {
        $f = $this->setupCentral();
        $this->postJson('/api/v1/digital/orders', $this->saleInput($f))->assertCreated()->assertJsonPath('data.status', 'succeeded');
        $before = [];
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries', 'topup_grants', 'digital_orders', 'digital_provider_attempts'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $migration = require database_path('migrations/2026_10_17_134658_add_granular_operational_permissions.php');
        $migration->up();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $this->assertSame(1, $this->purchases);
    }
}
