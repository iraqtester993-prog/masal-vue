<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\CatalogProduct;
use App\Models\Finance\Invoice;
use App\Models\Finance\LedgerTransaction;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\Ledger;
use App\Services\ManagementAuthority;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class FinanceCoreTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    private function tree(): array
    {
        $system = $this->account(AccountType::System);
        $main = $this->account(AccountType::MainAgent, $system);
        $sub = $this->account(AccountType::SubAgent, $main);
        $pos = $this->account(AccountType::Pos, $main);

        return [$system, $main, $sub, $pos];
    }

    private function depositInput(Account $account, string $amount = '1234.56', string $key = 'deposit-test-001'): array
    {
        return ['account_id' => $account->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => $amount, 'reference' => 'Bank receipt 1', 'idempotency_key' => $key];
    }

    private function enableProduct(Account $target): CatalogProduct
    {
        $p = CatalogProduct::factory()->create(['minimum_price' => '100.00']);
        $rule = DB::table('catalog_account_rules')->insertGetId(['target_account_id' => $target->id, 'authority_account_id' => $target->parent_id]);
        DB::table('catalog_rule_products')->insert(['rule_id' => $rule, 'product_id' => $p->id]);

        return $p;
    }

    public function test_unauthenticated_finance_returns_401(): void
    {
        $this->withHeader('X-Masal-Portal', 'admin')->getJson('/api/v1/finance/wallets')->assertUnauthorized();
    }

    public function test_dollar_deposits_are_rejected_without_posting_or_changing_wallets(): void
    {
        [$system, $main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', array_replace($this->depositInput($main), ['currency' => 'USD']))
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
    }

    public function test_documented_deposit_creates_balanced_immutable_entries_and_exact_wallet_balance(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated()->assertJsonPath('data.kind', 'deposit');
        $this->getJson('/api/v1/finance/wallets?account_id='.$main->id)->assertOk()->assertJsonPath('data.0.balance', '1234.56')->assertJsonPath('data.0.available', '1234.56');
        $this->assertSame([-123456, 123456], DB::table('finance_entries')->orderBy('id')->pluck('amount_minor')->map(fn ($v): int => (int) $v)->all());
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallets.deposit', 'subject_account_id' => $main->id]);
        $this->getJson('/api/v1/finance/ledger?account_id='.$main->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '1234.56');
    }

    public function test_same_idempotency_key_returns_original_posting_without_double_credit_and_changed_payload_returns_409(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $input = $this->depositInput($main);
        $id = $this->postJson('/api/v1/finance/deposits', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/finance/deposits', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/finance/deposits', array_replace($input, ['amount' => '1234.57']))->assertConflict();
        $this->assertDatabaseCount('finance_transactions', 1);
        $this->assertDatabaseCount('finance_entries', 2);
        $this->assertDatabaseCount('finance_operations', 1);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 123456]);
    }

    public static function invalidMoney(): array
    {
        return ['numeric float' => [12.34], 'zero' => ['0.00'], 'negative' => ['-1.00'], 'three decimals' => ['1.001'], 'scientific' => ['1e3'], 'too large' => ['10000000000000.00'], 'grouped value' => ['1,200.00'], 'leading zero' => ['001.00']];
    }

    #[DataProvider('invalidMoney')]
    public function test_invalid_amount_returns_422_without_wallet_or_ledger_writes(mixed $amount): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $input = $this->depositInput($main);
        $input['amount'] = $amount;
        $this->postJson('/api/v1/finance/deposits', $input)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_operations', 0);
    }

    public function test_unknown_mass_assignment_and_voucher_minting_return_422(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $input = $this->depositInput($main);
        $this->postJson('/api/v1/finance/deposits', $input + ['balance_minor' => 999])->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->postJson('/api/v1/finance/deposits', array_replace($input, ['service' => 'voucher']))->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_agent_cannot_mint_money_and_pos_cannot_transfer_403(): void
    {
        [$system,$main,$sub,$pos] = $this->tree();
        $this->asPortalUser($this->userFor($main));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertForbidden();
        $this->asPortalUser($this->userFor($pos));
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $pos->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '1.00', 'reference' => 'Test', 'idempotency_key' => 'pos-transfer-test'])->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_agent_transfer_debits_own_balance_and_credits_direct_child_without_minting(): void
    {
        [$system,$main,$sub] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $this->asPortalUser($this->userFor($main));
        $input = ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '234.56', 'reference' => 'Funding 1', 'idempotency_key' => 'transfer-test-001'];
        $this->postJson('/api/v1/finance/transfers', $input)->assertCreated();
        $this->postJson('/api/v1/finance/transfers', $input)->assertCreated();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 100000]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $sub->id, 'kind' => 'account', 'balance_minor' => 23456]);
        $this->assertDatabaseCount('finance_transactions', 2);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_insufficient_balance_rolls_back_entire_transfer_422(): void
    {
        [$system,$main,$sub] = $this->tree();
        $this->asPortalUser($this->userFor($main));
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '1.00', 'reference' => 'No balance', 'idempotency_key' => 'transfer-insufficient'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_operations', 0);
    }

    public function test_foreign_accounts_are_hidden_with_404_and_admin_direct_transfer_forbidden_403(): void
    {
        [$system,$main,$sub] = $this->tree();
        $foreign = $this->account(AccountType::MainAgent, $system);
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/finance/wallets?account_id='.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/finance/ledger?account_id='.$foreign->id)->assertNotFound();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '1.00', 'reference' => 'Attempt', 'idempotency_key' => 'admin-transfer-forbid'])->assertForbidden();
    }

    public function test_audit_failure_rolls_back_deposit_and_all_ledger_entries(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertInternalServerError();
        $this->assertDatabaseCount('finance_transactions', 0);
        $this->assertDatabaseCount('finance_entries', 0);
        $this->assertDatabaseCount('finance_wallets', 0);
        $this->assertDatabaseCount('finance_operations', 0);
    }

    public function test_funding_request_review_is_exact_idempotent_and_not_available_to_other_parent(): void
    {
        [$system,$main,$sub] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $this->asPortalUser($this->userFor($sub));
        $request = ['service' => 'cash', 'currency' => 'IQD', 'amount' => '34.56', 'purpose' => 'Request', 'idempotency_key' => 'funding-request-001'];
        $id = $this->postJson('/api/v1/finance/funding-requests', $request)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.from_account_id', $main->id)->json('data.id');
        $this->postJson('/api/v1/finance/funding-requests', $request)->assertCreated()->assertJsonPath('data.id', $id);
        $this->asPortalUser($this->userFor($system));
        $review = ['version' => 1, 'decision' => 'approve', 'reference' => 'Approve receipt', 'idempotency_key' => 'funding-approve-001'];
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/review', $review)->assertNotFound();
        $this->asPortalUser($this->userFor($main));
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/review', $review)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.version', 2);
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/review', $review)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $sub->id, 'kind' => 'account', 'balance_minor' => 3456]);
        $this->assertDatabaseCount('finance_transactions', 2);
    }

    public function test_pos_daily_limit_counts_cancelled_requests_and_resets_at_baghdad_midnight(): void
    {
        [$system,$main,$sub,$pos] = $this->tree();
        $this->asPortalUser($this->userFor($pos));
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(20, 59, 59));
        $input = ['service' => 'cash', 'currency' => 'IQD', 'amount' => '50000.00', 'idempotency_key' => 'pos-funding-first'];
        $id = $this->postJson('/api/v1/finance/funding-requests', $input)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/finance/funding-requests/'.$id.'/cancel', ['version' => 1, 'idempotency_key' => 'pos-funding-cancel'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/v1/finance/funding-requests', array_replace($input, ['idempotency_key' => 'pos-funding-second']))->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->travelTo(now()->addSecond());
        $this->postJson('/api/v1/finance/funding-requests', array_replace($input, ['idempotency_key' => 'pos-funding-next-day']))->assertCreated();
        $this->assertDatabaseCount('finance_funding_requests', 2);
    }

    public function test_policy_requires_system_permission_and_cas_and_rejects_decimal_duplicate(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($main));
        $this->getJson('/api/v1/finance/funding-policy')->assertForbidden();
        $this->asPortalUser($this->userFor($system));
        $this->putJson('/api/v1/finance/funding-policy', ['version' => 99, 'daily_limit' => 2, 'amounts' => ['100.00']])->assertConflict();
        $this->putJson('/api/v1/finance/funding-policy', ['version' => 1, 'daily_limit' => 2, 'amounts' => ['100', '100.00']])->assertUnprocessable()->assertJsonValidationErrors('amounts');
        $this->putJson('/api/v1/finance/funding-policy', ['version' => 1, 'daily_limit' => 2, 'amounts' => ['100.01', '50.00']])->assertOk()->assertJsonPath('data.amounts', ['50.00', '100.01'])->assertJsonPath('data.version', 2);
    }

    public function test_payable_creation_does_not_create_funds_and_settlement_is_partial_exact_and_cas(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $input = $this->depositInput($main, '1000.00', 'invoice-create-001') + ['kind' => 'payable', 'supplier' => 'Original supplier'];
        $id = $this->postJson('/api/v1/finance/invoices', $input)->assertCreated()->assertJsonPath('data.paid', '0.00')->json('data.id');
        $this->assertDatabaseCount('finance_transactions', 1);
        $settle = ['version' => 1, 'amount' => '400.10', 'reference' => 'Supplier receipt', 'idempotency_key' => 'invoice-settle-001'];
        $this->postJson('/api/v1/finance/invoices/'.$id.'/settle', $settle)->assertOk()->assertJsonPath('data.paid', '400.10')->assertJsonPath('data.remaining', '599.90')->assertJsonPath('data.status', 'partial');
        $this->postJson('/api/v1/finance/invoices/'.$id.'/settle', $settle)->assertOk()->assertJsonPath('data.paid', '400.10');
        $this->postJson('/api/v1/finance/invoices/'.$id.'/settle', array_replace($settle, ['idempotency_key' => 'invoice-stale-key']))->assertConflict();
        $this->postJson('/api/v1/finance/invoices/'.$id.'/settle', array_replace($settle, ['version' => 2, 'amount' => '600.00', 'idempotency_key' => 'invoice-too-much']))->assertUnprocessable();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 83446]);
        $this->assertDatabaseCount('finance_transactions', 2);
    }

    public function test_prices_are_unknown_until_explicit_configuration_and_changes_are_cas_and_reversible(): void
    {
        [$system,$main] = $this->tree();
        $product = $this->enableProduct($main);
        $this->asPortalUser($this->userFor($system));
        $this->getJson('/api/v1/finance/prices?account_id='.$main->id)->assertOk()->assertJsonPath('data.0.price', null);
        $input = ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '123.45', 'expected_price' => null]], 'idempotency_key' => 'price-request-first'];
        $id = $this->postJson('/api/v1/finance/price-requests', $input)->assertCreated()->assertJsonPath('data.status', 'approved')->json('data.id');
        $this->postJson('/api/v1/finance/price-requests', $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/finance/prices?account_id='.$main->id)->assertJsonPath('data.0.price', '123.45');
        $this->postJson('/api/v1/finance/price-requests', array_replace($input, ['idempotency_key' => 'price-stale-expected']))->assertConflict();
        $this->postJson('/api/v1/finance/price-requests/'.$id.'/reverse', ['version' => 2, 'reason' => 'Incorrect configured amount', 'idempotency_key' => 'price-reverse-first'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->getJson('/api/v1/finance/prices?account_id='.$main->id)->assertJsonPath('data.0.price', null);
    }

    public function test_main_owner_can_configure_own_prices_and_below_minimum_rejected_422(): void
    {
        [$system,$main] = $this->tree();
        $product = $this->enableProduct($main);
        $this->asPortalUser($this->userFor($main));
        $input = ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '99.99', 'expected_price' => null]], 'idempotency_key' => 'price-agent-request'];
        $this->postJson('/api/v1/finance/price-requests', $input)->assertUnprocessable()->assertJsonValidationErrors('price');
        $input['changes'][0]['price'] = '110.00';
        $this->postJson('/api/v1/finance/price-requests', $input)->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('finance_prices', ['account_id' => $main->id, 'product_id' => $product->id, 'price_minor' => 11000]);
    }

    public function test_posted_ledger_entries_cannot_be_edited_even_through_direct_database_update(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('finance_entries')->update(['amount_minor' => 1]);
    }

    public function test_posted_transactions_cannot_be_deleted_even_through_direct_database_query(): void
    {
        [$system,$main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('finance_transactions')->delete();
    }

    public function test_service_and_currency_mismatch_cannot_be_posted_to_ledger(): void
    {
        [$system,$main] = $this->tree();
        $actor = $this->userFor($system);
        $this->expectException(\LogicException::class);
        DB::transaction(function () use ($system, $main, $actor): void {
            $ledger = app(Ledger::class);
            $from = $ledger->wallet($system->id, 'cash', 'IQD', 'external');
            $to = $ledger->wallet($main->id, 'topup', 'IQD');
            $ledger->pair($actor, 'test', 'mismatch-ledger-key', [], $from, $to, 100, 'Mismatch');
        });
    }

    public function test_bulk_funding_rolls_back_all_recipients_if_total_exceeds_available_balance(): void
    {
        [$system, $main, $sub, $pos] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $this->asPortalUser($this->userFor($main));
        $input = ['service' => 'cash', 'currency' => 'IQD', 'rows' => [['to_account_id' => $sub->id, 'amount' => '60.00'], ['to_account_id' => $pos->id, 'amount' => '60.00']], 'reference' => 'Grouped funding', 'idempotency_key' => 'bulk-funding-001'];

        $this->postJson('/api/v1/finance/bulk-transfers', $input)->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('finance_transactions', 1);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 10000]);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $sub->id]);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $pos->id]);
    }

    public function test_bulk_funding_retry_is_atomic_and_does_not_duplicate_any_recipient_credit(): void
    {
        [$system, $main, $sub, $pos] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $this->asPortalUser($this->userFor($main));
        $input = ['service' => 'cash', 'currency' => 'IQD', 'rows' => [['to_account_id' => $sub->id, 'amount' => '30.10'], ['to_account_id' => $pos->id, 'amount' => '40.20']], 'reference' => 'Grouped funding', 'idempotency_key' => 'bulk-funding-002'];

        $first = $this->postJson('/api/v1/finance/bulk-transfers', $input)->assertCreated()->json('data');
        $this->postJson('/api/v1/finance/bulk-transfers', $input)->assertCreated()->assertJsonPath('data', $first);

        $this->assertDatabaseCount('finance_transactions', 3);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 2970]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $sub->id, 'kind' => 'account', 'balance_minor' => 3010]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $pos->id, 'kind' => 'account', 'balance_minor' => 4020]);
    }

    public function test_partial_recovery_is_exact_capped_idempotent_and_never_mutates_original_posting(): void
    {
        [$system, $main, $sub] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $this->asPortalUser($this->userFor($main));
        $id = $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '80.00', 'reference' => 'Recoverable funding', 'idempotency_key' => 'recovery-transfer-001'])->assertCreated()->json('data.id');
        $before = DB::table('finance_entries')->where('transaction_id', $id)->get()->map(fn ($entry): array => (array) $entry)->all();
        $input = ['amount' => '30.25', 'reason' => 'Unused balance', 'idempotency_key' => 'recovery-partial-001'];

        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', $input)->assertCreated()->assertJsonPath('data.amount', '30.25');
        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', $input)->assertCreated();
        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', array_replace($input, ['amount' => '49.76', 'idempotency_key' => 'recovery-too-much']))->assertUnprocessable();

        $this->assertSame($before, DB::table('finance_entries')->where('transaction_id', $id)->get()->map(fn ($entry): array => (array) $entry)->all());
        $this->assertDatabaseCount('finance_recoveries', 1);
        $this->assertDatabaseCount('finance_transactions', 3);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $sub->id, 'kind' => 'account', 'balance_minor' => 4975]);
        $this->getJson('/api/v1/finance/transfers')->assertOk()->assertJsonPath('data.0.recovered_amount', '30.25')->assertJsonPath('data.0.remaining', '49.75');
    }

    public function test_recovery_deadline_is_snapshotted_and_expiry_returns_409_after_policy_extension(): void
    {
        [$system, $main, $sub] = $this->tree();
        $this->freezeTime();
        $admin = $this->userFor($system);
        $this->asPortalUser($admin);
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $owner = $this->userFor($main);
        $this->asPortalUser($owner);
        $id = $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '80.00', 'reference' => 'Deadline funding', 'idempotency_key' => 'recovery-deadline-001'])->assertCreated()->json('data.id');
        $this->asPortalUser($admin);
        $this->putJson('/api/v1/finance/funding-policy', ['version' => 1, 'daily_limit' => 1, 'amounts' => ['50000.00'], 'recovery_hours' => 48])->assertOk();
        $this->travel(24)->hours();
        $this->asPortalUser($owner);

        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', ['amount' => '1.00', 'reason' => 'Expired', 'idempotency_key' => 'recovery-expired-001'])->assertConflict();

        $this->assertDatabaseCount('finance_recoveries', 0);
        $this->assertDatabaseCount('finance_transactions', 2);
    }

    public static function recoveryMutations(): array
    {
        return ['cannot reset recovered amount' => ['update'], 'cannot erase recovery history' => ['delete']];
    }

    #[DataProvider('recoveryMutations')]
    public function test_recovery_history_cannot_be_changed_to_bypass_original_transfer_cap(string $mutation): void
    {
        [$system, $main, $sub] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $this->asPortalUser($this->userFor($main));
        $transfer = $this->postJson('/api/v1/finance/transfers', ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'service' => 'cash', 'currency' => 'IQD', 'amount' => '80.00', 'reference' => 'Recovery cap', 'idempotency_key' => 'immutable-recovery-transfer'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/finance/transfers/'.$transfer.'/recover', ['amount' => '30.00', 'reason' => 'Unused funding', 'idempotency_key' => 'immutable-recovery-entry'])->assertCreated();

        $this->expectException(QueryException::class);
        if ($mutation === 'delete') {
            DB::table('finance_recoveries')->delete();
        } else {
            DB::table('finance_recoveries')->update(['amount_minor' => 1]);
        }
    }

    public function test_database_wallet_guards_reject_balance_minting_without_matching_entries(): void
    {
        [$system, $main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertCreated();
        $this->expectException(QueryException::class);

        DB::table('finance_wallets')->where('account_id', $main->id)->update(['balance_minor' => 999999, 'version' => 3]);
    }

    public function test_system_bulk_debits_selected_scoped_agent_and_preserves_actor_and_descendant_recovery(): void
    {
        [$system, $main, $sub] = $this->tree();
        $pos = $this->account(AccountType::Pos, $sub);
        $admin = $this->userFor($system);
        $owner = $this->userFor($main);
        $this->asPortalUser($admin);
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $payload = ['from_account_id' => $main->id, 'service' => 'cash', 'currency' => 'IQD', 'rows' => [['to_account_id' => $pos->id, 'amount' => '25.00']], 'reference' => 'Administrator bulk funding', 'idempotency_key' => 'system-bulk-agent-001'];

        $id = $this->postJson('/api/v1/finance/bulk-transfers', $payload)->assertCreated()->assertJsonPath('data.0.from_account_id', $main->id)->json('data.0.transaction_id');
        $this->postJson('/api/v1/finance/bulk-transfers', $payload)->assertCreated()->assertJsonPath('data.0.transaction_id', $id);
        $this->assertDatabaseHas('finance_transactions', ['id' => $id, 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $main->id, 'kind' => 'account', 'balance_minor' => 7500]);
        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $system->id, 'kind' => 'account']);
        $this->asPortalUser($owner);
        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', ['amount' => '10.00', 'reason' => 'Own original funding', 'idempotency_key' => 'recover-bulk-descendant'])->assertCreated();
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $pos->id, 'balance_minor' => 1500]);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    public function test_system_employee_bulk_respects_visible_funder_and_non_system_cannot_select_other_funder(): void
    {
        [$system, $main, $sub, $pos] = $this->tree();
        $other = $this->account(AccountType::MainAgent, $system);
        $this->userFor($system);
        $agent = $this->userFor($main);
        $employee = $this->financeEmployee($system, ['account.view', 'wallets.view', 'wallets.bulk', 'wallets.transfer'], [$main->id, $pos->id]);
        $this->asPortalUser($employee);
        $input = ['from_account_id' => $other->id, 'service' => 'cash', 'currency' => 'IQD', 'rows' => [['to_account_id' => $pos->id, 'amount' => '1.00']], 'reference' => 'Scope test', 'idempotency_key' => 'bulk-scope-denied-001'];
        $this->postJson('/api/v1/finance/bulk-transfers', $input)->assertNotFound();
        $this->asPortalUser($agent);
        $input['from_account_id'] = $sub->id;
        $this->postJson('/api/v1/finance/bulk-transfers', $input)->assertForbidden();
        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_database_rejects_posted_transaction_insert_without_entries(): void
    {
        $this->expectException(QueryException::class);

        LedgerTransaction::factory()->create(['posted' => true]);
    }

    public function test_receivable_stock_invoice_cash_settlement_never_credits_voucher_wallet_twice(): void
    {
        [$system, $main] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $invoice = Invoice::factory()->create(['account_id' => $main->id, 'kind' => 'receivable', 'service' => 'voucher', 'source_type' => 'stock_batch', 'source_id' => 10]);

        $this->postJson('/api/v1/finance/invoices/'.$invoice->id.'/settle', ['version' => 1, 'amount' => '100.00', 'reference' => 'Documented receipt', 'idempotency_key' => 'stock-invoice-settle'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseMissing('finance_wallets', ['account_id' => $main->id, 'service' => 'voucher']);
        $this->assertDatabaseHas('finance_wallets', ['account_id' => $system->id, 'kind' => 'account', 'service' => 'cash', 'balance_minor' => 10000]);
        $this->assertSame(0, (int) DB::table('finance_entries')->sum('amount_minor'));
    }

    private function financeEmployee(Account $account, array $permissions, array $roots): User
    {
        $profile = PermissionProfile::factory()->create(['account_id' => $account->id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, $permissions);
        $user = User::factory()->create();
        $member = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee', 'status' => 'active', 'permission_profile_id' => $profile->id, 'include_descendants' => false, 'version' => 1]);
        foreach ($roots as $root) {
            DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root]);
        }

        return $user;
    }

    public function test_price_employee_can_load_options_without_wallet_permission_and_proposal_remains_pending(): void
    {
        [$system, $main] = $this->tree();
        $this->userFor($main);
        $product = $this->enableProduct($main);
        $this->asPortalUser($this->financeEmployee($main, ['account.view', 'prices.view', 'prices.propose'], [$main->id]));

        $this->getJson('/api/v1/finance/options')->assertOk()->assertJsonPath('data.accounts.0.id', $main->id)->assertJsonPath('data.counts.today_requests', 0);
        $id = $this->postJson('/api/v1/finance/price-requests', ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '120.00', 'expected_price' => null]], 'idempotency_key' => 'employee-price-proposal'])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->getJson('/api/v1/finance/wallets')->assertForbidden();

        $this->assertDatabaseCount('finance_prices', 0);
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/price-requests/'.$id.'/review', ['version' => 1, 'decision' => 'approve', 'idempotency_key' => 'employee-price-reviewed'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('finance_prices', ['product_id' => $product->id, 'account_id' => $main->id, 'price_minor' => 12000]);
    }

    public function test_scoped_system_employee_cannot_use_deposit_to_mint_descendant_balance_403(): void
    {
        [$system, $main] = $this->tree();
        $this->userFor($system);
        $this->asPortalUser($this->financeEmployee($system, ['account.view', 'wallets.view', 'wallets.deposit'], [$main->id]));

        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main))->assertForbidden();

        $this->assertDatabaseCount('finance_transactions', 0);
    }

    public function test_search_dates_pagination_and_group_account_filters_apply_before_row_count_and_preserve_scope(): void
    {
        [$system, $main, $sub] = $this->tree();
        $this->asPortalUser($this->userFor($system));
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(20, 59, 59));
        $first = $this->depositInput($main, '1.00', 'date-boundary-first');
        $first['reference'] = 'Literal %_ reference';
        $this->postJson('/api/v1/finance/deposits', $first)->assertCreated();
        $this->travelTo(now()->addSecond());
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '2.00', 'date-boundary-second'))->assertCreated();
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($sub, '3.00', 'date-boundary-sub'))->assertCreated();
        $this->asPortalUser($this->userFor($main));

        $this->getJson('/api/v1/finance/ledger?from=2026-10-06&to=2026-10-06&q=%25_&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '1.00');
        $this->getJson('/api/v1/finance/ledger?from=2026-10-07&to=2026-10-07&account_ids[]='.$main->id.'&account_ids[]='.$system->id)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount', '2.00');
        $this->getJson('/api/v1/finance/ledger?sort=unsafe')->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->getJson('/api/v1/finance/ledger?from=2026-10-07&to=2026-10-06')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_recovery_cannot_take_balance_already_distributed_to_next_level(): void
    {
        [$system, $main, $sub] = $this->tree();
        $child = $this->account(AccountType::Pos, $sub);
        $this->asPortalUser($this->userFor($system));
        $this->postJson('/api/v1/finance/deposits', $this->depositInput($main, '100.00'))->assertCreated();
        $mainActor = $this->userFor($main);
        $this->asPortalUser($mainActor);
        $base = ['service' => 'cash', 'currency' => 'IQD', 'amount' => '80.00', 'reference' => 'Distributed funding'];
        $id = $this->postJson('/api/v1/finance/transfers', $base + ['from_account_id' => $main->id, 'to_account_id' => $sub->id, 'idempotency_key' => 'distributed-transfer-main'])->assertCreated()->json('data.id');
        $this->asPortalUser($this->userFor($sub));
        $this->postJson('/api/v1/finance/transfers', $base + ['from_account_id' => $sub->id, 'to_account_id' => $child->id, 'idempotency_key' => 'distributed-transfer-child'])->assertCreated();
        $this->asPortalUser($mainActor);

        $this->postJson('/api/v1/finance/transfers/'.$id.'/recover', ['amount' => '1.00', 'reason' => 'Already distributed', 'idempotency_key' => 'distributed-recovery'])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('finance_recoveries', 0);
        $this->assertDatabaseCount('finance_transactions', 3);
    }

    public function test_subagent_reads_main_price_inherited_and_price_filters_apply_before_pagination(): void
    {
        [$system, $main, $sub] = $this->tree();
        $product = $this->enableProduct($main);
        $this->asPortalUser($this->userFor($main));
        $this->postJson('/api/v1/finance/price-requests', ['account_id' => $main->id, 'changes' => [['product_id' => $product->id, 'price' => '101.25', 'expected_price' => null]], 'idempotency_key' => 'inherited-price-main'])->assertCreated();
        $this->asPortalUser($this->userFor($sub));

        $this->getJson('/api/v1/finance/prices?account_id='.$sub->id.'&price_state=priced&q='.urlencode($product->name))->assertOk()->assertJsonPath('data.0.price', '101.25')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/finance/prices?account_id='.$sub->id.'&price_state=unpriced')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/finance/prices?account_id='.$sub->id.'&to=2000-01-01')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
