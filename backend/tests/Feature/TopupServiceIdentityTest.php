<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\Wallet;
use App\Models\PosType;
use App\Services\AuditLogger;
use App\Services\Digital\TopupWallets;
use App\Services\Finance\FinanceServiceIdentity;
use App\Services\Finance\Ledger;
use App\Services\Finance\LegacyTopupTestReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class TopupServiceIdentityTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function fixture(): array
    {
        Http::preventStrayRequests();
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $point = $this->account(AccountType::Pos, $main);
        $foreign = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $agent = $this->userFor($main);
        $posUser = $this->userFor($point);
        $provider = CatalogProvider::factory()->create(['name' => 'آسياسيل · التعبئة المباشرة', 'connection' => 'API']);
        $unlinked = CatalogProvider::factory()->create(['name' => 'Topup', 'connection' => 'API']);
        $product = CatalogProduct::factory()->create(['provider_id' => $provider->id]);
        $connection = DigitalConnection::factory()->create(['account_id' => $system->id, 'provider_id' => null, 'provider' => 'topup', 'company_balance_minor' => 9400000]);
        DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id, 'type' => 'topup']);
        $service = 'api:'.$provider->id;
        $this->asPortalUser($admin);
        [$deposit, $funding] = DB::transaction(function () use ($admin, $agent, $system, $main, $point, $service): array {
            $ledger = app(Ledger::class);
            $external = $ledger->wallet($system->id, $service, 'IQD', 'external');
            $mainWallet = $ledger->wallet($main->id, $service, 'IQD');
            $pointWallet = $ledger->wallet($point->id, $service, 'IQD');
            $deposit = $ledger->pair($admin, 'deposit', 'legacy-original-deposit', [], $external, $mainWallet, 10000000, 'historical deposit');
            $funding = $ledger->pair($agent, 'funding', 'legacy-original-funding', [], $mainWallet, $pointWallet, 10000000, 'historical funding');

            return [$deposit, $funding];
        });
        $request = FundingRequest::create(['from_account_id' => $main->id, 'to_account_id' => $point->id, 'creator_id' => $posUser->id, 'service' => $service, 'currency' => 'IQD', 'amount_minor' => 10000000, 'status' => 'approved', 'transaction_id' => $funding->id]);
        $grant = TopupGrant::factory()->create(['target_account_id' => $main->id, 'main_account_id' => $main->id, 'from_account_id' => $system->id, 'connection_id' => $connection->id, 'balance_minor' => 9400000]);
        DB::transaction(function () use ($grant, $admin, $agent, $main, $point): void {
            $wallets = app(TopupWallets::class);
            $wallets->bootstrap($grant, $admin);
            $wallets->fund($agent, $main, $point, 1000000, 'actual-topup-funding', [], 'real funding');
        });

        return compact('system', 'main', 'point', 'foreign', 'admin', 'agent', 'posUser', 'provider', 'unlinked', 'product', 'connection', 'service', 'deposit', 'funding', 'request', 'grant');
    }

    private function financialState(): string
    {
        $state = [];
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries', 'finance_funding_requests', 'finance_invoices', 'finance_recoveries', 'finance_operations', 'topup_grants', 'topup_wallet_bindings', 'topup_finance_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return Ledger::hash($state);
    }

    public function test_canonical_options_and_wallets_never_add_the_legacy_balance_to_spendable_topup(): void
    {
        $f = $this->fixture();
        $before = $this->financialState();
        $this->asPortalUser($f['posUser']);
        $options = $this->getJson('/api/v1/finance/options?compact=1')->assertOk()->json('data');
        $this->assertSame(1, count(array_filter($options['services'], fn ($s): bool => $s['id'] === 'topup')));
        $this->assertNotContains($f['service'], array_column($options['services'], 'id'));
        $this->assertContains('api:'.$f['unlinked']->id, array_column($options['services'], 'id'));
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service', 'topup')->assertJsonPath('data.0.available', '10000.00')
            ->assertJsonPath('summary.available', '10000.00')->assertJsonPath('legacy_topup_summary.amount', '100000.00')->assertJsonPath('legacy_topup_summary.own_amount', '100000.00');
        $this->getJson('/api/v1/finance/wallets?service='.$f['service'].'&summary=1&metrics=0')->assertOk()->assertJsonPath('summary.available', '10000.00');
        $this->getJson('/api/v1/finance/wallets?account_id='.$f['foreign']->id)->assertNotFound();
        $this->assertSame($before, $this->financialState());
        Http::assertNothingSent();
    }

    public function test_topup_history_preserves_original_ids_amounts_and_balance_after_with_source_metadata(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['posUser']);
        $entry = DB::table('finance_entries')->where('transaction_id', $f['funding']->id)->where('amount_minor', '>', 0)->first();
        $rows = $this->getJson('/api/v1/finance/ledger?service=topup')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $row = collect($rows)->firstWhere('id', $entry->id);
        $this->assertSame(['topup', $f['service'], true, '100000.00', '100000.00'], [$row['service'], $row['source_service'], $row['legacy_service'], $row['amount'], $row['balance_after']]);
        $this->getJson('/api/v1/finance/transfers?service=topup')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/finance/funding-requests?service=topup')->assertOk()->assertJsonPath('data.0.id', $f['request']->id)->assertJsonPath('data.0.source_service', $f['service']);
        $this->asPortalUser($f['admin']);
        $report = $this->getJson('/api/v1/reports/sections/wallets-ledger/rows?service=topup')->assertOk()->json('data');
        $this->assertNotEmpty($report);
        $this->assertTrue(collect($report)->contains(fn ($row): bool => $row['service'] === 'topup' && $row['source_service'] === $f['service'] && $row['legacy_service']));
        $this->assertSame([$f['provider']->id], app(FinanceServiceIdentity::class)->topupProviderIds());
        $this->getJson('/api/v1/catalog/providers')->assertOk()->assertJsonFragment(['id' => $f['provider']->id, 'name' => 'Topup']);
    }

    public function test_legacy_summary_respects_account_filters_and_never_reveals_another_network_balance(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/finance/wallets?service=topup&account_id='.$f['point']->id.'&summary=1&metrics=0')
            ->assertOk()->assertJsonPath('legacy_topup_summary.amount', '100000.00')->assertJsonPath('legacy_topup_summary.own_amount', '0.00')->assertJsonPath('summary.available', '10000.00');
        $this->getJson('/api/v1/finance/wallets?service=topup&account_id='.$f['foreign']->id.'&summary=1&metrics=0')
            ->assertOk()->assertJsonPath('legacy_topup_summary.amount', '0.00')->assertJsonCount(0, 'legacy_topup_summary.accounts');
        $this->asPortalUser($this->userFor($f['foreign']));
        $this->getJson('/api/v1/finance/options?compact=1')->assertOk()->assertJsonPath('data.legacy_topup_summary.amount', '0.00')->assertJsonCount(0, 'data.legacy_topup_summary.accounts');
        $this->getJson('/api/v1/finance/ledger?service=topup')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_existing_legacy_invoice_collection_keeps_its_original_service_and_idempotency(): void
    {
        $f = $this->fixture();
        $invoice = Invoice::create(['account_id' => $f['point']->id, 'creator_id' => $f['admin']->id, 'kind' => 'receivable', 'service' => $f['service'], 'currency' => 'IQD', 'amount_minor' => 50000, 'reference' => 'existing legacy invoice']);
        $this->asPortalUser($f['admin']);
        $realBefore = Wallet::where('service', 'topup')->orderBy('id')->get()->toJson();
        $this->getJson('/api/v1/finance/invoices?service=topup')->assertOk()->assertJsonPath('data.0.id', $invoice->id)->assertJsonPath('data.0.source_service', $f['service']);
        $input = ['amount' => '500.00', 'version' => 1, 'reference' => 'historical collection', 'idempotency_key' => 'original-legacy-settlement'];
        $first = $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $input)->assertOk()->assertJsonPath('data.service', 'topup')->assertJsonPath('data.source_service', $f['service'])->json('data');
        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', $input)->assertOk()->assertJsonPath('data', $first);
        $this->assertDatabaseHas('finance_transactions', ['kind' => 'invoice-settlement', 'service' => $f['service'], 'idempotency_key' => $input['idempotency_key']]);
        $this->assertSame($realBefore, Wallet::where('service', 'topup')->orderBy('id')->get()->toJson());
    }

    public function test_a_same_name_unlinked_api_provider_remains_a_separate_financial_service(): void
    {
        $f = $this->fixture();
        $service = 'api:'.$f['unlinked']->id;
        $this->assertFalse(app(FinanceServiceIdentity::class)->isLegacy($service));
        DB::transaction(function () use ($f, $service): void {
            $ledger = app(Ledger::class);
            $ledger->pair($f['admin'], 'deposit', 'unrelated-api-historical', [], $ledger->wallet($f['system']->id, $service, 'IQD', 'external'), $ledger->wallet($f['main']->id, $service, 'IQD'), 10000, 'unrelated historical balance');
        });
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $f['main']->id, 'to_account_id' => $f['point']->id, 'service' => $service, 'currency' => 'IQD', 'amount' => '100.00', 'reference' => 'unrelated API', 'idempotency_key' => 'unrelated-api-transfer'])
            ->assertCreated()->assertJsonPath('data.service', $service)->assertJsonPath('data.legacy_service', false);
    }

    public function test_provider_rename_status_and_connection_changes_cannot_reopen_legacy_writes_or_split_history(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $this->patchJson('/api/v1/catalog/providers/'.$f['provider']->id, ['name' => 'اسم جديد للشركة', 'supplier' => $f['provider']->supplier, 'connection' => 'ملفات', 'version' => $f['provider']->version])->assertOk()->assertJsonPath('data.name', 'Topup');
        $f['provider']->refresh()->update(['status' => 'disabled']);
        $this->assertSame([$f['provider']->id], app(FinanceServiceIdentity::class)->topupProviderIds());
        $this->assertSame('Topup', app(FinanceServiceIdentity::class)->providerName($f['provider']->fresh()));
        $this->asPortalUser($f['posUser']);
        $options = $this->getJson('/api/v1/finance/options')->assertOk()->json('data.services');
        $this->assertNotContains($f['service'], array_column($options, 'id'));
        $this->getJson('/api/v1/finance/ledger?service=topup')->assertOk()->assertJsonCount(2, 'data')->assertJsonFragment(['source_service' => $f['service'], 'legacy_service' => true]);
        $f['provider']->update(['connection' => 'API', 'status' => 'active']);
        $before = $this->financialState();
        $this->postJson('/api/v1/finance/funding-requests', ['service' => $f['service'], 'currency' => 'IQD', 'amount' => '100000.00', 'idempotency_key' => 'renamed-legacy-rejection'])->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->assertSame($before, $this->financialState());
    }

    public function test_populated_reference_options_and_point_profile_keep_non_provider_names_unchanged(): void
    {
        $f = $this->fixture();
        $type = PosType::factory()->create(['name' => 'نوع نقطة حقيقي', 'status' => 'active']);
        $this->asPortalUser($f['admin']);
        $this->getJson('/api/v1/reference/options')->assertOk()->assertJsonFragment(['id' => $type->id, 'name' => $type->name]);
        $this->getJson('/api/v1/accounts/'.$f['point']->id.'/reference-profile')->assertOk()->assertJsonFragment(['id' => $type->id, 'name' => $type->name]);
    }

    public function test_topup_product_cannot_move_provider_when_only_an_original_invoice_exists_without_a_wallet(): void
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $admin = $this->userFor($system);
        $provider = CatalogProvider::factory()->create(['connection' => 'API']);
        $destination = CatalogProvider::factory()->create(['connection' => 'API']);
        $product = CatalogProduct::factory()->create(['provider_id' => $provider->id]);
        $connection = DigitalConnection::factory()->create(['account_id' => $system->id, 'provider_id' => null, 'provider' => 'topup']);
        DigitalOffer::factory()->create(['connection_id' => $connection->id, 'product_id' => $product->id, 'type' => 'topup']);
        $invoice = Invoice::create(['account_id' => $main->id, 'creator_id' => $admin->id, 'kind' => 'receivable', 'service' => 'api:'.$provider->id, 'currency' => 'IQD', 'amount_minor' => 50000, 'reference' => 'invoice before wallet']);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->asPortalUser($admin);
        $input = $product->only(['name', 'kind', 'face_value', 'currency', 'minimum_price', 'daily_limit_type', 'daily_quantity', 'daily_amount', 'field_policy', 'extra_fields', 'display_order', 'import_codes', 'receipt_language', 'receipt_width', 'receipt_header', 'receipt_footer', 'allowed_cities', 'version']);
        $this->patchJson('/api/v1/catalog/products/'.$product->id, $input + ['provider_id' => $destination->id])->assertUnprocessable()->assertJsonValidationErrors('provider_id');
        $this->assertSame($provider->id, $product->fresh()->provider_id);
        $this->getJson('/api/v1/finance/invoices?service=topup')->assertOk()->assertJsonPath('data.0.id', $invoice->id)->assertJsonPath('data.0.service', 'topup');
    }

    public function test_new_legacy_writes_and_pending_approvals_are_rejected_before_finance_operation_creation(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['agent']);
        $transfer = ['from_account_id' => $f['main']->id, 'to_account_id' => $f['point']->id, 'service' => $f['service'], 'currency' => 'IQD', 'amount' => '100.00', 'reference' => 'blocked legacy', 'idempotency_key' => 'blocked-legacy-transfer'];
        $before = $this->financialState();
        $this->postJson('/api/v1/finance/transfers', $transfer)->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->postJson('/api/v1/finance/bulk-transfers', ['from_account_id' => $f['main']->id, 'service' => 'topup', 'currency' => 'IQD', 'rows' => [['to_account_id' => $f['point']->id, 'service' => $f['service'], 'amount' => '100.00']], 'reference' => 'blocked row', 'idempotency_key' => 'blocked-legacy-bulk'])->assertUnprocessable();
        $this->asPortalUser($f['posUser']);
        $this->postJson('/api/v1/finance/funding-requests', ['service' => $f['service'], 'currency' => 'IQD', 'amount' => '100000.00', 'idempotency_key' => 'blocked-legacy-request'])->assertUnprocessable();
        $this->asPortalUser($f['admin']);
        $this->postJson('/api/v1/finance/invoices', ['account_id' => $f['point']->id, 'kind' => 'receivable', 'service' => $f['service'], 'currency' => 'IQD', 'amount' => '100.00', 'reference' => 'blocked invoice', 'idempotency_key' => 'blocked-legacy-invoice'])->assertUnprocessable();
        $this->assertSame($before, $this->financialState());
        $pending = FundingRequest::create(['from_account_id' => $f['main']->id, 'to_account_id' => $f['point']->id, 'creator_id' => $f['posUser']->id, 'service' => $f['service'], 'currency' => 'IQD', 'amount_minor' => 10000]);
        $this->asPortalUser($f['agent']);
        $this->postJson('/api/v1/finance/funding-requests/'.$pending->id.'/review', ['version' => 1, 'decision' => 'approve', 'reference' => 'blocked approval', 'idempotency_key' => 'blocked-legacy-approval'])->assertUnprocessable();
        $this->assertDatabaseCount('finance_operations', 0);
        $this->assertDatabaseHas('finance_funding_requests', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function test_original_legacy_recovery_still_posts_to_its_original_wallets_and_retry_is_idempotent(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['agent']);
        $input = ['amount' => '1000.00', 'reason' => 'original funding recovery', 'idempotency_key' => 'original-legacy-recover'];
        $first = $this->postJson('/api/v1/finance/transfers/'.$f['funding']->id.'/recover', $input)->assertCreated()->assertJsonPath('data.source_service', $f['service'])->assertJsonPath('data.service', 'topup')->json('data.id');
        $this->postJson('/api/v1/finance/transfers/'.$f['funding']->id.'/recover', $input)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['point']->id, 'service' => $f['service'], 'balance_minor' => 9900000]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $f['point']->id, 'service' => 'topup', 'balance_minor' => 1000000]);
        $this->assertSame(9400000, $f['grant']->fresh()->balance_minor);
    }

    public function test_confirmed_test_balance_is_reversed_atomically_with_audit_and_original_history_preserved(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $original = DB::table('finance_entries')->whereIn('transaction_id', [$f['deposit']->id, $f['funding']->id])->orderBy('id')->get()->toJson();
        $realBefore = Wallet::where('service', 'topup')->orderBy('id')->get()->toJson();
        $request = Request::create('/private-reconciliation', 'POST');
        $request->attributes->set('portal', 'admin');
        $service = app(LegacyTopupTestReconciliation::class);
        $result = $service->reverse($f['admin'], $f['deposit']->id, $f['funding']->id, 10000000, 'confirmed-test-reconciliation', $request);
        $this->assertSame($result, $service->reverse($f['admin'], $f['deposit']->id, $f['funding']->id, 10000000, 'confirmed-test-reconciliation', $request));
        $this->assertSame($original, DB::table('finance_entries')->whereIn('transaction_id', [$f['deposit']->id, $f['funding']->id])->orderBy('id')->get()->toJson());
        $this->assertSame($realBefore, Wallet::where('service', 'topup')->orderBy('id')->get()->toJson());
        $this->assertSame(0, Wallet::where('service', $f['service'])->where('balance_minor', '<>', 0)->count());
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('finance_transactions', ['id' => $result['deposit_reversal_id'], 'reversal_of' => $f['deposit']->id]);
        $this->assertDatabaseHas('finance_transactions', ['id' => $result['funding_reversal_id'], 'reversal_of' => $f['funding']->id]);
        $this->asPortalUser($f['posUser']);
        $this->getJson('/api/v1/finance/wallets?service=topup&summary=1&metrics=0')->assertOk()->assertJsonPath('summary.available', '10000.00')->assertJsonPath('legacy_topup_summary.amount', '0.00');
        $this->asPortalUser($f['agent']);
        $before = $this->financialState();
        $this->postJson('/api/v1/finance/transfers/'.$f['funding']->id.'/recover', ['amount' => '1.00', 'reason' => 'must remain reversed', 'idempotency_key' => 'cannot-recover-reversed'])->assertConflict();
        $this->getJson('/api/v1/finance/transfers?service=topup&recoverable=1')->assertOk()->assertJsonMissing(['id' => $f['funding']->id]);
        $this->assertSame($before, $this->financialState());
    }

    public function test_reconciliation_rolls_back_both_reversals_when_audit_fails(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        $before = $this->financialState();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('synthetic audit failure'));
        try {
            app(LegacyTopupTestReconciliation::class)->reverse($f['admin'], $f['deposit']->id, $f['funding']->id, 10000000, 'rollback-test-reconciliation', Request::create('/private-reconciliation', 'POST'));
            $this->fail('Audit failure must abort reconciliation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->financialState());
    }

    public function test_reconciliation_rejects_mismatched_amount_or_existing_hold_without_any_posting(): void
    {
        $f = $this->fixture();
        $this->asPortalUser($f['admin']);
        DB::transaction(function () use ($f): void {
            $wallet = Wallet::where('account_id', $f['point']->id)->where('service', $f['service'])->lockForUpdate()->firstOrFail();
            $wallet->update(['held_minor' => 1, 'version' => $wallet->version + 1]);
        });
        $before = $this->financialState();
        try {
            app(LegacyTopupTestReconciliation::class)->reverse($f['admin'], $f['deposit']->id, $f['funding']->id, 10000000, 'held-test-reconciliation', Request::create('/private-reconciliation', 'POST'));
            $this->fail('A held balance must abort reconciliation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->financialState());
    }
}
