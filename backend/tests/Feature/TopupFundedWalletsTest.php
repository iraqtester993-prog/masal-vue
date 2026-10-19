<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Digital\TopupCategory;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\Invoice;
use App\Models\Finance\Policy;
use App\Models\Finance\Wallet;
use App\Services\Digital\DigitalConfiguration;
use App\Services\Digital\TopupAccounting;
use App\Services\Digital\TopupWallets;
use App\Services\Finance\Ledger;
use App\Services\Operations\ArchiveBlockers;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesSupport;
use Tests\TestCase;

class TopupFundedWalletsTest extends TestCase
{
    use CreatesSupport, RefreshDatabase;

    private int $purchases = 0;

    private string $providerResult = 'Succeed';

    private int $inventory = 100000;

    private int $inventoryReads = 0;

    private bool $assertUncachedProvider = false;

    private int $uncachedProviderCalls = 0;

    private ?\Closure $eligibilityHook = null;

    private bool $purchaseTimeout = false;

    private function fixture(bool $fund = true): array
    {
        Http::preventStrayRequests();
        config(['digital.topup.driver' => 'masal_v2_1', 'digital.purchases_enabled' => true]);
        Http::fake(function ($request) {
            if ($this->assertUncachedProvider) {
                $this->assertFalse(request()->attributes->has('masal.locked_authorization_cache'));
                $this->uncachedProviderCalls++;
            }
            if (str_ends_with($request->url(), '/inventory')) {
                $this->inventoryReads++;

                return Http::response((string) $this->inventory, 200, ['Content-Type' => 'application/json']);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1]]]);
            }
            if (str_ends_with($request->url(), '/checkEligibility')) {
                if ($this->eligibilityHook) {
                    ($this->eligibilityHook)();
                }

                return Http::response(['status' => true]);
            }
            $this->purchases++;
            if ($this->purchaseTimeout) {
                throw new ConnectionException('Synthetic provider timeout');
            }
            if ($this->providerResult === 'Succeed') {
                $this->inventory -= 6000;
            }

            return Http::response(['id' => 'funded-topup-'.$this->purchases, 'status' => $this->providerResult]);
        });
        $f = $this->supportFixture();
        $f['pos']->update(['device_lock_enabled' => false]);
        $this->asPortalUser($f['admin']);
        $connectionId = $this->postJson('/api/v1/digital/connections', ['provider' => 'topup', 'account_id' => $f['system']->id, 'version' => 0, 'active' => true, 'credential' => 'funded-topup-private', 'offers' => [], 'idempotency_key' => 'funded-connect-001'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/digital/connections/'.$connectionId.'/sync', ['version' => 1])->assertOk();
        $category = TopupCategory::where('connection_id', $connectionId)->firstOrFail();
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['connection_id' => $connectionId, 'allocation_balance' => '100000.00', 'category_ids' => [$category->id], 'active' => true, 'version' => 0, 'idempotency_key' => 'funded-allocate-001'])->assertOk();
        foreach ([['agent', 'sub'], ['subUser', 'branch'], ['branchUser', 'pos']] as [$user, $target]) {
            $this->asPortalUser($f[$user]);
            $this->putJson('/api/v1/topup/distribution/'.$f[$target]->id, ['version' => 0, 'category_ids' => [$category->id], 'active' => true, 'idempotency_key' => 'funded-categories-'.$target])->assertOk();
        }
        $this->asPortalUser($f['agent']);
        $this->putJson('/api/v1/topup/prices', ['version' => 1, 'prices' => [['category_id' => $category->id, 'price' => '6500.00']], 'idempotency_key' => 'funded-agent-price'])->assertOk();
        $f += ['connection' => DigitalConnection::findOrFail($connectionId), 'category' => $category];
        if ($fund) {
            $this->postJson('/api/v1/finance/transfers', $this->fundingInput($f))->assertCreated();
        }

        return $f;
    }

    public function test_device_revocation_during_preflight_stops_purchase_and_releases_only_its_original_hold(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'revoked-after-eligibility');
        $f['pos']->update(['device_lock_enabled' => true, 'device_binding_enabled' => true]);
        $proofKey = 'synthetic-previously-verified-proof-key';
        $device = DB::table('pos_registered_devices')->insertGetId(['account_id' => $f['pos']->id, 'identifier_hash' => hash('sha256', 'synthetic-approved-device'),
            'proof_public_key' => $proofKey, 'kind' => 'pos', 'app_version' => '1.3.0', 'status' => 'approved', 'version' => 1,
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_device_sessions')->where('account_id', $f['pos']->id)->update(['registered_device_id' => $device, 'proof_key_hash' => hash('sha256', $proofKey), 'proof_verified_at' => now()]);
        $this->eligibilityHook = fn () => DB::table('pos_registered_devices')->where('id', $device)->update(['status' => 'revoked', 'version' => 2]);
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => [$wallet->id, $wallet->balance_minor, $wallet->held_minor])->all();
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_active', false);
        $this->assertSame(0, $this->purchases);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => [$wallet->id, $wallet->balance_minor, $wallet->held_minor])->all());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_expired_device_session_during_preflight_stops_purchase_without_consuming_point_balance(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'expired-after-eligibility');
        $this->eligibilityHook = fn () => DB::table('sales_device_sessions')->where('account_id', $f['pos']->id)->update(['expires_at' => now()->subSecond()]);
        $before = $this->wallet($f['pos']->id)->balance_minor;
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'failed');
        $this->assertSame(0, $this->purchases);
        $this->assertSame($before, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertSame(0, $this->wallet($f['pos']->id)->held_minor);
    }

    public function test_login_device_transfer_during_provider_preflight_stops_purchase_without_consuming_wallet_or_stock(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'login-device-transferred-preflight');
        $this->eligibilityHook = fn () => DB::table('pos_login_bindings')->where('account_id', $f['pos']->id)->increment('version');
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => [$wallet->id, $wallet->balance_minor, $wallet->held_minor])->all();

        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.reservation_active', false);

        $this->assertSame(0, $this->purchases);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => [$wallet->id, $wallet->balance_minor, $wallet->held_minor])->all());
        $this->assertDatabaseCount('finance_invoices', 0);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_manual_provider_followup_is_owner_only_audited_idempotent_and_never_resolves_or_repeats_a_purchase(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'manual-followup-timeout');
        $this->purchaseTimeout = true;
        $order = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.manual_review_required', true)->json('data');
        $before = [Wallet::orderBy('id')->get()->toJson(), DB::table('finance_entries')->orderBy('id')->get()->toJson(), TopupGrant::orderBy('id')->get()->toJson()];
        $path = '/api/v1/digital/orders/'.$order['id'].'/review-note';
        $note = ['version' => $order['version'], 'reference' => 'PROVIDER-CASE-123', 'note' => 'Provider support case awaiting signed statement.', 'current_password' => 'password', 'idempotency_key' => 'manual-followup-note-001'];
        $this->asPortalUser($f['agent']);
        $this->postJson($path, $note)->assertForbidden();
        $this->asPortalUser($f['admin']);
        $this->postJson($path, array_replace($note, ['current_password' => 'incorrect']))->assertUnprocessable();
        $result = $this->postJson($path, $note)->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.reservation_active', true)
            ->assertJsonPath('data.company_purchase_confirmed', false)->assertJsonPath('data.manual_review.reference', 'PROVIDER-CASE-123')->json('data');
        $this->postJson($path, $note)->assertOk()->assertJsonPath('data.version', $result['version']);
        $this->postJson($path, array_replace($note, ['note' => 'Changed note with same key']))->assertConflict();
        $f['admin']->update(['password' => Hash::make('123456789')]);
        $this->asPortalUser($f['admin']);
        $this->postJson($path, array_replace($note, ['current_password' => '123456789']))->assertOk()->assertJsonPath('data.version', $result['version']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'digital.review-note')->count());
        $this->assertSame(1, $this->purchases);
        $this->assertSame($before, [Wallet::orderBy('id')->get()->toJson(), DB::table('finance_entries')->orderBy('id')->get()->toJson(), TopupGrant::orderBy('id')->get()->toJson()]);
        $this->assertStringNotContainsString('PROVIDER-CASE-123', DigitalOrder::findOrFail($order['id'])->getRawOriginal('manual_review'));
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/digital/orders/'.$order['id'])->assertOk()->assertJsonMissingPath('data.manual_review');
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    private function fundingInput(array $f, string $key = 'fund-point-10000'): array
    {
        return ['from_account_id' => $f['main']->id, 'to_account_id' => $f['pos']->id, 'service' => 'topup', 'currency' => 'IQD', 'amount' => '10000.00', 'reference' => 'تمويل Topup للاختبار', 'idempotency_key' => $key];
    }

    private function sale(array $f, string $key = 'funded-sale-001'): array
    {
        $this->asPortalUser($f['posUser']);
        $offer = $this->getJson('/api/v1/topup/available')->assertOk()->json('data.categories.0.offer');
        $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());

        return ['request_id' => $key, 'connection_id' => $f['connection']->id, 'offer_id' => $offer['id'], 'offer_version' => $offer['version'], 'expected_retail' => $offer['retail'], 'mobile' => '07701234567', 'confirm_mobile' => '07701234567'];
    }

    private function wallet(int $account, string $kind = 'account'): Wallet
    {
        return Wallet::where('account_id', $account)->where('service', 'topup')->where('currency', 'IQD')->where('kind', $kind)->firstOrFail();
    }

    public function test_sale_provider_http_and_acknowledgement_do_not_retain_the_locked_authorization_cache(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'uncached-provider-phase');
        $this->assertUncachedProvider = true;
        $id = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded')->json('data.id');
        $this->assertSame(3, $this->uncachedProviderCalls);
        $this->assertFalse(request()->attributes->has('masal.locked_authorization_cache'));
        $this->postJson('/api/v1/digital/orders/'.$id.'/acknowledge', [])->assertOk();
        $this->assertFalse(request()->attributes->has('masal.locked_authorization_cache'));
        $this->assertSame(3, $this->uncachedProviderCalls);
        $this->assertSame(350000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_main_funding_moves_available_money_and_sale_records_nonspendable_margin_once(): void
    {
        $f = $this->fixture();
        $this->assertSame(9000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(1000000, $this->wallet($f['pos']->id)->balance_minor);
        $input = $this->sale($f);
        $id = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded')->assertJsonMissingPath('data.admin_price')->assertJsonMissingPath('data.agent_markup')->json('data.id');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, $this->purchases);
        $this->assertSame(9000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(350000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertSame(50000, $this->wallet($f['main']->id, 'external')->balance_minor);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 9400000, 'spent_minor' => 600000, 'held_minor' => 0]);
        $this->assertDatabaseHas('finance_invoices', ['source_type' => 'digital_topup', 'source_id' => $id, 'amount_minor' => 650000]);
        $this->assertDatabaseCount('topup_finance_events', 3);
        $this->assertSame(['ok' => true, 'issues' => [], 'read_only' => true], app(TopupWallets::class)->auditInvariants());
    }

    public function test_granted_categories_remain_visible_without_funding_and_insufficient_sale_never_calls_provider(): void
    {
        $f = $this->fixture(false);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/topup/available')->assertOk()->assertJsonCount(1, 'data.categories')->assertJsonPath('data.categories.0.offer.wallet_available', '0.00')->assertJsonPath('data.categories.0.offer.purchasable', false)->assertJsonPath('data.categories.0.offer.funding_required', true);
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertUnprocessable();
        $this->assertSame(0, $this->purchases);
        $this->assertDatabaseCount('digital_orders', 0);
        $this->assertSame(10000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $f['pos']->id, 'service' => 'topup']);
    }

    public function test_unknown_provider_holds_point_retail_and_grant_admin_without_holding_main_again(): void
    {
        $f = $this->fixture();
        $this->providerResult = 'Unknown';
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->assertJsonPath('data.status', 'review');
        $this->assertSame(650000, $this->wallet($f['pos']->id)->held_minor);
        $this->assertSame(0, $this->wallet($f['main']->id)->held_minor);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'held_minor' => 600000, 'balance_minor' => 10000000]);
        $this->assertSame(0, Invoice::count());
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_generic_topup_deposit_invoice_and_main_funding_request_cannot_mint_money(): void
    {
        $f = $this->fixture(false);
        $this->asPortalUser($f['admin']);
        $body = ['account_id' => $f['main']->id, 'service' => 'topup', 'currency' => 'IQD', 'amount' => '100.00', 'reference' => 'forged topup', 'idempotency_key' => 'forged-topup-deposit'];
        $this->postJson('/api/v1/finance/deposits', $body)->assertUnprocessable();
        $this->postJson('/api/v1/finance/invoices', $body + ['kind' => 'receivable'])->assertUnprocessable();
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/funding-requests', ['service' => 'topup', 'currency' => 'IQD', 'amount' => '100.00', 'idempotency_key' => 'forged-main-request'])->assertUnprocessable();
        $this->assertSame(10000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertDatabaseCount('finance_transactions', 1);
    }

    public function test_descendant_point_request_is_approved_by_main_from_the_same_funded_pool_once(): void
    {
        $f = $this->fixture(false);
        Policy::findOrFail(1)->update(['amounts_minor' => [1000000]]);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/finance/options?compact=1')->assertOk()->assertJsonPath('data.parent_account_id', $f['branch']->id)->assertJsonPath('data.topup_funder_account_id', $f['main']->id);
        $requestId = $this->postJson('/api/v1/finance/funding-requests', ['service' => 'topup', 'currency' => 'IQD', 'amount' => '10000.00', 'idempotency_key' => 'point-request-topup'])->assertCreated()->assertJsonPath('data.from_account_id', $f['main']->id)->json('data.id');
        $this->asPortalUser($f['branchUser']);
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', ['version' => 1, 'decision' => 'approve', 'reference' => 'wrong funder', 'idempotency_key' => 'wrong-review-topup'])->assertNotFound();
        $this->asPortalUser($f['agent']);
        $review = ['version' => 1, 'decision' => 'approve', 'reference' => 'اعتماد تمويل Topup', 'idempotency_key' => 'approve-point-topup'];
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', $review)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/v1/finance/funding-requests/'.$requestId.'/review', $review)->assertOk();
        $this->assertSame(9000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(1000000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertDatabaseCount('finance_transactions', 2);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_recovery_cannot_take_held_money_and_does_not_restore_spent_or_profit_to_main(): void
    {
        $f = $this->fixture();
        $transferId = DB::table('finance_transactions')->where('kind', 'transfer')->value('id');
        $this->providerResult = 'Unknown';
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated();
        $this->asPortalUser($f['agent']);
        $recovery = ['amount' => '3500.00', 'reason' => 'استرجاع المتاح فقط', 'idempotency_key' => 'recover-available-topup'];
        $this->postJson('/api/v1/finance/transfers/'.$transferId.'/recover', $recovery)->assertCreated();
        $this->postJson('/api/v1/finance/transfers/'.$transferId.'/recover', $recovery)->assertCreated();
        $this->postJson('/api/v1/finance/transfers/'.$transferId.'/recover', ['amount' => '1.00', 'reason' => 'لا يسحب المحجوز', 'idempotency_key' => 'recover-held-topup'])->assertUnprocessable();
        $this->assertSame(9350000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(650000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertSame(650000, $this->wallet($f['pos']->id)->held_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_administrator_cannot_withdraw_funded_point_balance_and_employee_cannot_read_margin_or_cost(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $this->putJson('/api/v1/topup/distribution/'.$f['main']->id, ['connection_id' => $f['connection']->id, 'allocation_balance' => '0.00', 'category_ids' => [$f['category']->id], 'active' => true, 'version' => 2, 'idempotency_key' => 'withdraw-point-funded'])->assertUnprocessable();
        $this->assertSame(1000000, $this->wallet($f['pos']->id)->balance_minor);
        $employee = $this->supportEmployee($f['main'], $f['main'], ['account.view', 'wallets.view']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()
            ->assertJsonMissingPath('topup_summary.agent_margin_accounting')->assertJsonMissingPath('topup_summary.administrator_spent')->assertJsonMissingPath('topup_summary.allocation_balance')->assertJsonPath('summary.own_available', '90000.00');
        $narrow = $this->supportEmployee($f['main'], $f['pos'], ['account.view', 'wallets.view', 'data.cost', 'data.profit']);
        $this->asPortalUser($narrow);
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonPath('topup_summary', null)->assertJsonPath('summary.balance', '10000.00');
        $this->assertDatabaseCount('finance_transactions', 2);
    }

    public function test_funded_point_cannot_be_reparented_and_profit_only_employee_cannot_read_cost_inferred_margin(): void
    {
        $f = $this->fixture();
        $originalParent = $f['pos']->parent_id;
        $this->asPortalUser($f['admin']);
        $this->patchJson('/api/v1/accounts/'.$f['pos']->id, ['version' => $f['pos']->version, 'parent_id' => $f['foreign']->id, 'reason' => 'لا ينقل رصيد Topup إلى حصة أخرى'])->assertUnprocessable();
        $this->assertSame($originalParent, $f['pos']->fresh()->parent_id);
        $this->assertSame(1000000, $this->wallet($f['pos']->id)->balance_minor);
        $employee = $this->supportEmployee($f['main'], $f['main'], ['account.view', 'wallets.view', 'data.profit']);
        $this->asPortalUser($employee);
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonMissingPath('topup_summary.agent_margin_accounting')->assertJsonMissingPath('topup_summary.allocation_balance');
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_success_refund_replay_restores_original_point_budget_without_crediting_main_profit(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->json('data.id');
        $order = DigitalOrder::findOrFail($id);
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/digital/orders/'.$id.'/refund-status', ['version' => $order->version, 'idempotency_key' => 'unsupported-direct-refund'])->assertConflict();
        config(['digital.topup.driver' => 'normalized', 'digital.gateway_url' => 'https://1.1.1.1/masal', 'digital.allowed_hosts' => ['1.1.1.1'], 'digital.contract_verified' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/verify' => Http::response(['status' => 'refunded', 'transactionId' => $order->company_transaction_id])]);
        $input = ['version' => $order->version, 'idempotency_key' => 'funded-refund-once'];
        $this->postJson('/api/v1/digital/orders/'.$id.'/refund-status', $input)->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->postJson('/api/v1/digital/orders/'.$id.'/refund-status', $input)->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertSame(9000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(1000000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertSame(0, $this->wallet($f['main']->id, 'external')->balance_minor);
        $this->assertSame(10000000, $f['connection']->fresh()->company_balance_minor);
        $this->assertDatabaseHas('topup_grants', ['target_account_id' => $f['main']->id, 'balance_minor' => 10000000, 'spent_minor' => 0]);
        $this->assertDatabaseHas('finance_invoices', ['source_id' => $id, 'source_type' => 'digital_topup', 'status' => 'cancelled']);
        $this->assertDatabaseCount('topup_finance_events', 4);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
        Http::assertSentCount(1);
    }

    public function test_legacy_opening_is_idempotent_holds_only_main_and_failure_does_not_invent_point_money(): void
    {
        $f = $this->fixture(false);
        $f['connection']->update(['company_balance_minor' => 12000000]);
        $legacyPoint = $this->account(AccountType::Pos, $f['foreign']);
        $legacySeller = $this->userFor($legacyPoint);
        $grant = TopupGrant::factory()->create(['main_account_id' => $f['foreign']->id, 'target_account_id' => $f['foreign']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $f['connection']->id, 'balance_minor' => 2000000, 'held_minor' => 600000]);
        $offer = $f['connection']->offers()->firstOrFail();
        $order = DigitalOrder::factory()->create(['connection_id' => $f['connection']->id, 'offer_id' => $offer->id, 'product_id' => $offer->product_id, 'account_id' => $legacyPoint->id, 'main_account_id' => $f['foreign']->id, 'creator_id' => $legacySeller->id, 'provider' => 'topup', 'topup_grant_id' => $grant->id, 'admin_price_minor' => 600000, 'quoted_retail_minor' => 650000, 'quoted_cost_minor' => 600000, 'status' => 'review', 'reservation_active' => true]);
        DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
        DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
        $this->assertSame(2000000, $this->wallet($f['foreign']->id)->balance_minor);
        $this->assertSame(600000, $this->wallet($f['foreign']->id)->held_minor);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $legacyPoint->id, 'service' => 'topup']);
        $this->assertDatabaseCount('finance_transactions', 2);
        DB::transaction(function () use ($order): void {
            $order->update(['status' => 'failed', 'reservation_active' => false]);
            app(TopupAccounting::class)->resolve($order, 'failed');
        });
        $this->assertSame(0, $this->wallet($f['foreign']->id)->held_minor);
        $this->assertSame(2000000, $this->wallet($f['foreign']->id)->balance_minor);
        $this->assertSame(0, $this->purchases);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_archive_checks_grant_and_nonspendable_margin_even_when_main_available_wallet_is_zero(): void
    {
        $f = $this->fixture(false);
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/transfers', array_replace($this->fundingInput($f), ['amount' => '100000.00']))->assertCreated();
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated();
        $this->assertSame(0, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(50000, $this->wallet($f['main']->id, 'external')->balance_minor);
        $this->assertTrue(collect(app(ArchiveBlockers::class)->reasons($f['main']))->contains(fn ($reason) => str_contains($reason, 'Topup')));
    }

    public function test_auditor_rejects_funding_metadata_bound_to_a_different_grant_without_changing_any_wallet(): void
    {
        $f = $this->fixture();
        $other = TopupGrant::factory()->create(['main_account_id' => $f['foreign']->id, 'target_account_id' => $f['foreign']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $f['connection']->id]);
        DB::transaction(function () use ($f, $other): void {
            $wallets = app(TopupWallets::class);
            $wallets->bootstrap($other, $f['admin']);
            $ledger = app(Ledger::class);
            $tx = $ledger->pair($f['agent'], 'transfer', 'misbound-metadata-funding', ['fixture' => 'cross-grant'], $this->wallet($f['main']->id), $this->wallet($f['pos']->id), 100, 'metadata fixture');
            DB::table('topup_finance_events')->insert(['transaction_id' => $tx->id, 'grant_id' => $other->id, 'connection_id' => $f['connection']->id, 'phase' => 'funding', 'event_key' => 'misbound-funding:'.$tx->id, 'created_at' => now()]);
        });
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all();
        $audit = app(TopupWallets::class)->auditInvariants();
        $this->assertFalse($audit['ok']);
        $this->assertContains('movement_posting_grant_direction', $audit['issues']);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
    }

    public function test_bootstrap_rejects_noncentral_connection_before_creating_or_crediting_wallets(): void
    {
        $f = $this->fixture(false);
        $connection = DigitalConnection::factory()->create(['account_id' => $f['foreign']->id, 'provider' => 'topup', 'company_balance_minor' => 2000000]);
        $grant = TopupGrant::factory()->create(['main_account_id' => $f['foreign']->id, 'target_account_id' => $f['foreign']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $connection->id, 'balance_minor' => 2000000]);
        $count = Wallet::count();
        try {
            DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
            $this->fail('Noncentral Topup bootstrap must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($count, Wallet::count());
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $f['foreign']->id, 'service' => 'topup']);
        $this->assertSame(2000000, $grant->fresh()->balance_minor);
    }

    public function test_main_dashboard_and_connection_show_own_available_wallet_after_funding_and_sale(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated();
        $this->asPortalUser($f['agent']);
        foreach (['', '?view=cards'] as $query) {
            $card = collect($this->getJson('/api/v1/dashboard/summary'.$query)->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
            $this->assertSame('90000.00', $card['remaining']);
        }
        $this->getJson('/api/v1/digital/connections?provider=topup')->assertOk()->assertJsonPath('balance.amount', '90000.00')->assertJsonPath('data.0.company_balance', '90000.00');
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonPath('summary.own_available', '90000.00')->assertJsonPath('topup_summary.allocation_balance', '94000.00')->assertJsonPath('topup_summary.agent_margin_accounting', '500.00');
    }

    public function test_paid_invoice_blocks_accounting_refund_without_changing_point_or_main_budget(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->json('data.id');
        $invoice = Invoice::where('source_type', 'digital_topup')->where('source_id', $id)->firstOrFail();
        $this->supportGrant($f['agent'], 'invoices.settle');
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', ['version' => 1, 'amount' => '1000.00', 'reference' => 'تحصيل جزئي', 'idempotency_key' => 'funded-cash-collection'])->assertOk();
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all();
        try {
            DB::transaction(function () use ($id): void {
                $order = DigitalOrder::findOrFail($id);
                $order->update(['status' => 'refunded', 'refunded_at' => now()]);
                app(TopupAccounting::class)->resolve($order, 'refunded');
            });
            $this->fail('Paid receivable requires explicit settlement before refund.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
        $this->assertSame('succeeded', DigitalOrder::findOrFail($id)->status);
        $this->assertSame(100000, $invoice->fresh()->paid_minor);
        $this->assertDatabaseCount('topup_finance_events', 3);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
        $this->asPortalUser($f['admin']);
        config(['digital.topup.driver' => 'normalized', 'digital.gateway_url' => 'https://1.1.1.1/masal', 'digital.allowed_hosts' => ['1.1.1.1'], 'digital.contract_verified' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/verify' => Http::response(['status' => 'refunded', 'transactionId' => DigitalOrder::findOrFail($id)->company_transaction_id])]);
        $attempts = ProviderAttempt::count();
        $this->postJson('/api/v1/digital/orders/'.$id.'/refund-status', ['version' => DigitalOrder::findOrFail($id)->version, 'idempotency_key' => 'paid-refund-preflight'])->assertConflict();
        Http::assertNothingSent();
        $this->assertSame($attempts, ProviderAttempt::count());
        $this->assertDatabaseMissing('digital_provider_attempts', ['active_order_id' => $id]);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
    }

    public function test_pending_or_unknown_refund_prevents_cash_collection_until_the_provider_result_is_confirmed(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->json('data.id');
        $invoice = Invoice::where('source_type', 'digital_topup')->where('source_id', $id)->firstOrFail();
        $attempt = ProviderAttempt::factory()->create(['order_id' => $id, 'actor_id' => $f['admin']->id, 'active_order_id' => $id, 'operation' => 'refund-status', 'status' => 'dispatching', 'started_at' => now()]);
        $this->supportGrant($f['agent'], 'invoices.settle');
        $this->asPortalUser($f['agent']);
        $input = ['version' => 1, 'amount' => '1000.00', 'reference' => 'تحصيل بعد تأكيد الاسترجاع', 'idempotency_key' => 'collection-refund-race'];
        $transactions = DB::table('finance_transactions')->count();
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $input)->assertConflict();
        $attempt->update(['active_order_id' => null, 'status' => 'unknown', 'result_status' => 'review', 'finished_at' => now()]);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $input)->assertConflict();
        $this->assertSame(0, $invoice->fresh()->paid_minor);
        $this->assertSame($transactions, DB::table('finance_transactions')->count());
        ProviderAttempt::factory()->create(['order_id' => $id, 'actor_id' => $f['admin']->id, 'active_order_id' => null, 'operation' => 'refund-status', 'status' => 'received', 'result_status' => 'succeeded', 'started_at' => now(), 'finished_at' => now()]);
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $input)->assertOk();
        $this->assertSame(100000, $invoice->fresh()->paid_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_legacy_review_confirmed_then_refunded_settles_only_the_original_main_budget_once(): void
    {
        $f = $this->fixture(false);
        $point = $this->account(AccountType::Pos, $f['foreign']);
        $seller = $this->userFor($point);
        $grant = TopupGrant::factory()->create(['main_account_id' => $f['foreign']->id, 'target_account_id' => $f['foreign']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $f['connection']->id, 'balance_minor' => 2000000, 'held_minor' => 600000]);
        $offer = $f['connection']->offers()->firstOrFail();
        $order = DigitalOrder::factory()->create(['connection_id' => $f['connection']->id, 'offer_id' => $offer->id, 'product_id' => $offer->product_id, 'account_id' => $point->id, 'main_account_id' => $f['foreign']->id, 'creator_id' => $seller->id, 'provider' => 'topup', 'topup_grant_id' => $grant->id, 'admin_price_minor' => 600000, 'quoted_retail_minor' => 650000, 'quoted_cost_minor' => 600000, 'status' => 'review', 'reservation_active' => true, 'company_transaction_id' => 'legacy-confirmed-refund', 'receipt_ref' => 'legacy-refund-receipt', 'actual_cost_minor' => 600000, 'actual_retail_minor' => 650000, 'cost_basis' => 'provider_response']);
        DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
        DB::transaction(function () use ($order): void {
            $order->update(['status' => 'refunded', 'reservation_active' => false, 'resolved_at' => now(), 'refunded_at' => now()]);
            app(TopupAccounting::class)->resolve($order, 'refunded');
            app(TopupAccounting::class)->resolve($order, 'refunded');
        });
        $this->assertSame(2000000, $this->wallet($f['foreign']->id)->balance_minor);
        $this->assertSame(0, $this->wallet($f['foreign']->id)->held_minor);
        $this->assertSame(0, $this->wallet($f['foreign']->id, 'external')->balance_minor);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $point->id, 'service' => 'topup']);
        $this->assertSame(2000000, $grant->fresh()->balance_minor);
        $this->assertSame(0, $grant->fresh()->spent_minor);
        $this->assertDatabaseHas('finance_invoices', ['source_type' => 'digital_topup', 'source_id' => $order->id, 'amount_minor' => 650000, 'status' => 'cancelled']);
        $this->assertSame(2, DB::table('topup_finance_events')->where('order_id', $order->id)->count());
        $this->assertSame(0, $this->purchases);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_bootstrap_refuses_unbound_legacy_topup_money_without_relabeling_or_crediting_it(): void
    {
        $f = $this->fixture(false);
        DB::transaction(function () use ($f): void {
            $ledger = app(Ledger::class);
            $unbound = $ledger->wallet($f['foreign']->id, 'topup', 'IQD');
            $clearing = $ledger->wallet($f['system']->id, 'topup', 'IQD', 'external');
            $ledger->pair($f['admin'], 'deposit', 'legacy-unbound-money', ['legacy' => true], $clearing, $unbound, 10000, 'Legacy Topup fixture');
        });
        $grant = TopupGrant::factory()->create(['main_account_id' => $f['foreign']->id, 'target_account_id' => $f['foreign']->id, 'from_account_id' => $f['system']->id, 'connection_id' => $f['connection']->id, 'balance_minor' => 2000000]);
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all();
        $events = DB::table('topup_finance_events')->count();
        try {
            DB::transaction(fn () => app(TopupWallets::class)->bootstrap($grant, $f['admin']));
            $this->fail('Unbound historical money requires explicit reconciliation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
        $this->assertSame($events, DB::table('topup_finance_events')->count());
        $this->assertDatabaseMissing('topup_wallet_bindings', ['wallet_id' => $this->wallet($f['foreign']->id)->id]);
        $this->assertSame(2000000, $grant->fresh()->balance_minor);
    }

    public function test_funded_order_cannot_omit_the_administrator_price_or_change_its_wallet_basis(): void
    {
        $f = $this->fixture();
        $offer = $f['connection']->offers()->firstOrFail();
        $grant = TopupGrant::where('target_account_id', $f['main']->id)->firstOrFail();
        try {
            DigitalOrder::factory()->create(['connection_id' => $f['connection']->id, 'offer_id' => $offer->id, 'product_id' => $offer->product_id, 'account_id' => $f['pos']->id, 'main_account_id' => $f['main']->id, 'creator_id' => $f['posUser']->id, 'provider' => 'topup', 'topup_grant_id' => $grant->id, 'admin_price_minor' => null, 'budget_basis' => 'point_budget_v1', 'budget_wallet_id' => $this->wallet($f['pos']->id)->id]);
            $this->fail('A funded order requires its immutable administrator price.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Invalid Topup funded budget', $exception->getMessage());
        }
        $id = $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->json('data.id');
        try {
            DB::table('digital_orders')->where('id', $id)->update(['budget_basis' => 'legacy_main', 'budget_wallet_id' => null]);
            $this->fail('A settled order must retain the wallet charged at purchase.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Topup funding basis is immutable', $exception->getMessage());
        }
        $this->assertSame('point_budget_v1', DigitalOrder::findOrFail($id)->budget_basis);
        $this->assertSame($this->wallet($f['pos']->id)->id, DigitalOrder::findOrFail($id)->budget_wallet_id);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_cost_hidden_employee_sees_own_available_and_sales_without_administrator_prices_in_any_summary(): void
    {
        $f = $this->fixture();
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated();
        $employee = $this->supportEmployee($f['main'], $f['main'], ['account.view', 'dashboard.view', 'digital.view', 'digital.assign', 'topup.view', 'topup.prices', 'wallets.view', 'data.profit']);
        $this->asPortalUser($employee);
        foreach (['', '?view=cards'] as $query) {
            $card = collect($this->getJson('/api/v1/dashboard/summary'.$query)->assertOk()->json('data.cards'))->firstWhere('key', 'digital:topup');
            $this->assertSame('6500.00', $card['value']);
            $this->assertSame('90000.00', $card['remaining']);
            foreach ($card['balanceRows'] as $row) {
                $this->assertNull($row['spent']);
            }
        }
        $this->getJson('/api/v1/topup/distribution?target_account_id='.$f['sub']->id)->assertOk()->assertJsonMissingPath('data.allocation.spent')->assertJsonMissingPath('data.allocation.balance')->assertJsonMissingPath('data.categories.0.admin_price');
        $this->getJson('/api/v1/digital/connections?provider=topup')->assertOk()->assertJsonMissingPath('data.0.allocation_spent')->assertJsonPath('balance.amount', '90000.00');
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonMissingPath('topup_summary.agent_margin_accounting')->assertJsonMissingPath('topup_summary.allocation_balance');
        $version = TopupGrant::where('target_account_id', $f['main']->id)->value('version');
        $this->putJson('/api/v1/topup/prices', ['version' => $version, 'prices' => [['category_id' => $f['category']->id, 'price' => '6600.00']], 'idempotency_key' => 'hidden-cost-price-edit'])->assertOk()->assertJsonMissingPath('data.balance')->assertJsonMissingPath('data.spent');
    }

    public function test_topup_purchase_never_fetches_inventory_automatically_and_replay_never_debits_twice(): void
    {
        $f = $this->fixture();
        $reads = $this->inventoryReads;
        $input = $this->sale($f);
        $id = $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, $this->purchases);
        $this->assertSame($reads, $this->inventoryReads);
        $this->assertSame(9400000, $f['connection']->fresh()->company_balance_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
    }

    public function test_reverse_order_provider_balances_never_overwrite_or_double_subtract_topup_estimates(): void
    {
        $f = $this->fixture();
        $second = $this->account(AccountType::Pos, $f['main']);
        $second->update(['device_lock_enabled' => false]);
        $secondUser = $this->userFor($second);
        $this->asPortalUser($f['agent']);
        $this->putJson('/api/v1/topup/distribution/'.$second->id, ['version' => 0, 'category_ids' => [$f['category']->id], 'active' => true, 'idempotency_key' => 'reverse-order-grant'])->assertOk();
        $this->postJson('/api/v1/finance/transfers', array_replace($this->fundingInput($f), ['to_account_id' => $second->id, 'idempotency_key' => 'reverse-order-fund']))->assertCreated();
        $this->providerResult = 'Unknown';
        $a = $this->postJson('/api/v1/digital/orders', $this->sale($f, 'reverse-order-a'))->assertCreated()->assertJsonPath('data.status', 'review')->json('data.id');
        $other = array_replace($f, ['pos' => $second, 'posUser' => $secondUser]);
        $b = $this->postJson('/api/v1/digital/orders', $this->sale($other, 'reverse-order-b'))->assertCreated()->assertJsonPath('data.status', 'review')->json('data.id');
        config(['digital.topup.driver' => 'normalized', 'digital.gateway_url' => 'https://1.1.1.1/masal', 'digital.allowed_hosts' => ['1.1.1.1'], 'digital.contract_verified' => true]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $first = $request['requestId'] === 'reverse-order-a';

            return Http::response(['status' => 'succeeded', 'transactionId' => $first ? 'reverse-a' : 'reverse-b', 'receiptRef' => $first ? 'receipt-a' : 'receipt-b', 'cost' => '6000.00', 'retail' => '6500.00', 'currency' => 'IQD', 'remaining_balance' => $first ? '94000.00' : '88000.00']);
        });
        $bInput = ['version' => DigitalOrder::findOrFail($b)->version, 'idempotency_key' => 'reverse-confirm-b'];
        $this->asPortalUser($secondUser);
        $this->postJson('/api/v1/digital/orders/'.$b.'/verify', $bInput)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(9400000, $f['connection']->fresh()->company_balance_minor);
        $this->asPortalUser($f['posUser']);
        $aInput = ['version' => DigitalOrder::findOrFail($a)->version, 'idempotency_key' => 'reverse-confirm-a'];
        $this->postJson('/api/v1/digital/orders/'.$a.'/verify', $aInput)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->postJson('/api/v1/digital/orders/'.$a.'/verify', $aInput)->assertOk();
        $this->asPortalUser($secondUser);
        $this->postJson('/api/v1/digital/orders/'.$b.'/verify', $bInput)->assertOk();
        $this->assertSame(8800000, $f['connection']->fresh()->company_balance_minor);
        $this->assertSame(8000000, $this->wallet($f['main']->id)->balance_minor);
        $this->assertSame(350000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertSame(350000, $this->wallet($second->id)->balance_minor);
        $this->assertSame(100000, $this->wallet($f['main']->id, 'external')->balance_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
        Http::assertSentCount(2);
    }

    public function test_explicit_topup_inventory_sync_is_allowed_only_when_no_purchase_is_unresolved(): void
    {
        $f = $this->fixture();
        $this->inventory = 112000;
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all();
        $this->asPortalUser($f['admin']);
        $reads = $this->inventoryReads;
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertOk()->assertJsonPath('data.company_balance', '112000.00');
        $this->assertSame($reads + 1, $this->inventoryReads);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
        $this->providerResult = 'Unknown';
        $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->assertJsonPath('data.status', 'review');
        $this->asPortalUser($f['admin']);
        $reads = $this->inventoryReads;
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertConflict();
        $this->assertSame($reads, $this->inventoryReads);
        $this->assertSame(11200000, $f['connection']->fresh()->company_balance_minor);
        $this->assertSame(650000, $this->wallet($f['pos']->id)->held_minor);
    }

    public function test_explicit_inventory_rejects_active_or_latest_unknown_refund_before_provider_call(): void
    {
        $f = $this->fixture();
        $id = $this->postJson('/api/v1/digital/orders', $this->sale($f))->assertCreated()->json('data.id');
        $attempt = ProviderAttempt::factory()->create(['order_id' => $id, 'actor_id' => $f['admin']->id, 'active_order_id' => $id, 'operation' => 'refund-status', 'status' => 'dispatching', 'started_at' => now()]);
        $this->asPortalUser($f['admin']);
        $reads = $this->inventoryReads;
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertConflict();
        $attempt->update(['active_order_id' => null, 'status' => 'unknown', 'result_status' => 'review', 'finished_at' => now()]);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertConflict();
        $this->assertSame($reads, $this->inventoryReads);
        ProviderAttempt::factory()->create(['order_id' => $id, 'actor_id' => $f['admin']->id, 'active_order_id' => null, 'operation' => 'refund-status', 'status' => 'received', 'result_status' => 'succeeded', 'started_at' => now(), 'finished_at' => now()]);
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertOk();
        $this->assertSame($reads + 1, $this->inventoryReads);
        $this->assertSame(9400000, $f['connection']->fresh()->company_balance_minor);
    }

    public function test_explicit_inventory_rejects_a_completed_purchase_during_http_without_holding_account_locks(): void
    {
        $f = $this->fixture();
        $input = $this->sale($f, 'purchase-during-inventory');
        $this->asPortalUser($f['admin']);
        $level = DB::transactionLevel();
        $beforeVersion = $f['connection']->fresh()->version;
        Http::swap(new Factory);
        Http::fake(function ($request) use ($f, $input, $level) {
            if (str_ends_with($request->url(), '/inventory')) {
                $this->assertSame($level, DB::transactionLevel());
                $this->asPortalUser($f['posUser']);
                $this->postJson('/api/v1/digital/device-session', ['app_version' => '1.0.0'])->assertOk();
                $this->withCookie('masal_pos_session', session()->getId());
                $this->postJson('/api/v1/digital/orders', $input)->assertCreated()->assertJsonPath('data.status', 'succeeded');
                $this->asPortalUser($f['admin']);

                return Http::response('100000', 200, ['Content-Type' => 'application/json']);
            }
            if (str_ends_with($request->url(), '/checkEligibility')) {
                return Http::response(['status' => true]);
            }
            if (str_ends_with($request->url(), '/products')) {
                return Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1]]]);
            }

            return Http::response(['id' => 'during-inventory-purchase', 'status' => 'Succeed']);
        });
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertConflict();
        $this->assertSame(9400000, $f['connection']->fresh()->company_balance_minor);
        $this->assertSame($beforeVersion, $f['connection']->fresh()->version);
        $this->assertSame(350000, $this->wallet($f['pos']->id)->balance_minor);
        $this->assertTrue(app(TopupWallets::class)->auditInvariants()['ok']);
        $this->assertDatabaseCount('digital_orders', 1);
        $this->assertDatabaseCount('digital_provider_attempts', 1);
    }

    public function test_overlapping_inventory_reads_only_accept_the_first_completed_sync_version(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $before = Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all();
        $beforeVersion = $f['connection']->fresh()->version;
        $level = DB::transactionLevel();
        $reads = 0;
        Http::swap(new Factory);
        Http::fake(function () use ($f, $level, &$reads) {
            $this->assertSame($level, DB::transactionLevel());
            $reads++;
            if ($reads === 1) {
                $request = Request::create('/inventory-sync', 'POST');
                $request->attributes->set('portal', 'admin');
                $result = app(DigitalConfiguration::class)->balance($f['admin'], $f['connection']->id, $request);
                $this->assertSame('112000.00', $result['company_balance']);
                $this->assertSame($f['connection']->version + 1, $result['version']);

                return Http::response('113000', 200, ['Content-Type' => 'application/json']);
            }

            return Http::response('112000', 200, ['Content-Type' => 'application/json']);
        });
        $this->postJson('/api/v1/digital/connections/'.$f['connection']->id.'/balance')->assertConflict();
        $this->assertSame(2, $reads);
        $this->assertSame(11200000, $f['connection']->fresh()->company_balance_minor);
        $this->assertSame($beforeVersion + 1, $f['connection']->fresh()->version);
        $this->assertSame($before, Wallet::orderBy('id')->get()->map(fn ($wallet) => $wallet->getAttributes())->all());
    }
}
