<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Wallet;
use App\Models\User;
use App\Services\Finance\Ledger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Explicit existing-QA native acceptance; never migrates, clears history, or submits a sale. */
class LedgerChainNativeTest extends TestCase
{
    private const QA_BASE = '/home/dananiriq/masal-verification/finance-stock-20261006-01';

    /** @return resource */
    private function guard(): mixed
    {
        if (getenv('MASAL_LEDGER_CHAIN_NATIVE') !== '1') {
            $this->markTestSkipped('Requires the explicitly retained disposable MariaDB journal fixture.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        $this->assertSame('dananiriq_masalverify', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(self::QA_BASE, realpath(base_path()));
        $this->assertSame(self::QA_BASE.'/storage', realpath(storage_path()));
        $lock = fopen('/home/dananiriq/masal-verification/.audit84.lock', 'c');
        $this->assertIsResource($lock);
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'Other QA tests must release the existing fixture first.');
        try {
            $this->assertSame(100, Account::where('type', AccountType::MainAgent)->count());
            $this->assertSame(10000, Account::where('type', AccountType::Pos)->count());
            $this->assertGreaterThanOrEqual(3000000, DB::table('digital_orders')->count());
            $this->assertGreaterThanOrEqual(1000000, DB::table('finance_entries')->count());
            $this->assertSame(0, DB::table('digital_provider_attempts')->whereNotNull('active_order_id')->count());
            $this->assertSame(0, DB::table('sales')->where('print_pending', true)->count());
        } catch (\Throwable $failure) {
            flock($lock, LOCK_UN);
            fclose($lock);
            throw $failure;
        }

        return $lock;
    }

    /** @return array{0:User,1:int,2:int} */
    private function actors(): array
    {
        $actor = User::with('membership.account')->where('login', 'scale-agent-1')->firstOrFail();
        $this->assertStringEndsWith('@example.invalid', $actor->email);
        $this->assertSame(AccountType::MainAgent, $actor->membership->account->type);
        $system = Account::where('type', AccountType::System)->firstOrFail();
        $shared = DB::table('finance_wallets')->where('account_id', $system->id)->where('service', 'voucher')->where('currency', 'IQD')->where('kind', 'external')->value('id');
        $counter = DB::table('finance_wallets')->where('account_id', $actor->membership->account_id)->where('service', 'voucher')->where('currency', 'IQD')->where('kind', 'external')->value('id');
        $this->assertNotNull($shared);
        $this->assertNotNull($counter);
        $this->assertNotSame($shared, $counter);
        $this->assertGreaterThanOrEqual(500000, DB::table('finance_entries')->where('wallet_id', $shared)->count());
        $this->assertGreaterThanOrEqual(500000, DB::table('finance_entries')->where('wallet_id', $counter)->count());

        return [$actor, (int) $shared, (int) $counter];
    }

    private function fingerprint(): string
    {
        $facts = ['wallets' => DB::table('finance_wallets')->orderBy('id')->get(['id', 'account_id', 'service', 'currency', 'kind', 'balance_minor', 'held_minor']),
            'cards' => DB::table('stock_cards')->orderBy('id')->get(['id', 'account_id', 'product_id', 'sale_id', 'status', 'credit_minor', 'credit_held']),
            'grants' => DB::table('topup_grants')->orderBy('id')->get(),
            'connections' => DB::table('digital_connections')->orderBy('id')->get(['id', 'company_balance_minor']),
            'sales' => DB::table('sales')->count(), 'orders' => DB::table('digital_orders')->count(), 'invoices' => DB::table('finance_invoices')->count()];

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    private function pair(User $actor, int $debit, int $credit, string $run, string $step): LedgerTransaction
    {
        return DB::transaction(function () use ($actor, $debit, $credit, $run, $step): LedgerTransaction {
            $wallets = Wallet::whereIn('id', [$debit, $credit])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            return app(Ledger::class)->pair($actor, 'qa-chain-native', $run.'-'.$step, ['qa' => true, 'run' => $run, 'step' => $step], $wallets[$debit], $wallets[$credit], 7, 'Synthetic native current-chain acceptance');
        });
    }

    private function draft(User $actor, string $run, string $step): int
    {
        return LedgerTransaction::create(['actor_id' => $actor->id, 'kind' => 'qa-chain-native', 'service' => 'voucher', 'currency' => 'IQD', 'reference' => 'Synthetic rollback-only chain validation',
            'idempotency_key' => $run.'-'.$step, 'payload_hash' => Ledger::hash([$run, $step]), 'posted' => false, 'created_at' => now()])->id;
    }

    private function entry(int $transaction, int $wallet, int $amount, int $after, ?int $id = null): array
    {
        $entry = ['transaction_id' => $transaction, 'wallet_id' => $wallet, 'amount_minor' => $amount, 'balance_after_minor' => $after, 'created_at' => now()];
        if ($id !== null) {
            $entry['id'] = $id;
        }

        return $entry;
    }

    private function assertRejected(callable $statement, string $reason): void
    {
        $rejected = false;
        try {
            $statement();
        } catch (QueryException $failure) {
            $this->assertSame('45000', $failure->errorInfo[0], $reason);
            $this->assertSame(1644, (int) $failure->errorInfo[1], $reason);
            $rejected = true;
        }
        $this->assertTrue($rejected, $reason);
    }

    private function appendDraftLeg(int $transaction, int $walletId, int $amount): void
    {
        $wallet = Wallet::whereKey($walletId)->lockForUpdate()->firstOrFail();
        $after = $wallet->balance_minor + $amount;
        DB::table('finance_entries')->insert($this->entry($transaction, $walletId, $amount, $after));
        $wallet->update(['balance_minor' => $after, 'version' => $wallet->version + 1]);
    }

    /** @param array{0:int,1:int,2:int,3:int} $wallets */
    private function settlePostingProof(User $actor, int $transaction, array $wallets, bool $thirdCommitted, string $run): LedgerTransaction
    {
        return DB::transaction(function () use ($actor, $transaction, $wallets, $thirdCommitted, $run): LedgerTransaction {
            $posting = LedgerTransaction::whereKey($transaction)->lockForUpdate()->firstOrFail();
            if (! $posting->posted) {
                if ($thirdCommitted && ! DB::table('finance_entries')->where('transaction_id', $transaction)->where('wallet_id', $wallets[3])->exists()) {
                    $this->appendDraftLeg($transaction, $wallets[3], -7);
                }
                $posting->update(['posted' => true]);
            }
            $entries = DB::table('finance_entries')->where('transaction_id', $transaction)->get();
            $current = Wallet::whereIn('id', $entries->pluck('wallet_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $legs = $entries->map(fn ($entry): array => ['wallet' => $current[$entry->wallet_id], 'amount' => -(int) $entry->amount_minor])->all();

            return app(Ledger::class)->post($actor, 'qa-chain-native', $run.'-posting-reverse', ['qa' => true, 'run' => $run, 'proof' => 'current-posting'], 'voucher', 'IQD', 'Reverse owned native posting proof', $legs);
        });
    }

    public function test_committed_entry_chain_overrides_a_stale_repeatable_read_snapshot_without_changing_existing_money(): void
    {
        $lock = $this->guard();
        $original = DB::getDefaultConnection();
        $alias = 'ledger_chain_native_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $source = DB::connection($original);
        $run = 'qachain-'.bin2hex(random_bytes(6));
        $bPosted = $aPosted = false;
        try {
            [$actor, $shared, $counter] = $this->actors();
            $before = $this->fingerprint();
            $entryCount = DB::table('finance_entries')->count();
            $transactionCount = DB::table('finance_transactions')->count();
            fwrite(STDOUT, 'LEDGER_CHAIN_RR_GUARD '.json_encode(['run' => $run, 'database' => 'dananiriq_masalverify', 'shared_wallet' => $shared, 'counter_wallet' => $counter,
                'will_add_balanced_postings' => 2, 'will_add_entries' => 4, 'expected_net_existing_balance_change' => 0, 'provider_calls' => 0, 'new_sales' => 0]).PHP_EOL);
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->beginTransaction();
            $oldLatest = $source->table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->first();
            $this->assertNotNull($oldLatest);
            DB::setDefaultConnection($alias);
            $competing = $this->pair($actor, $shared, $counter, $run, 'competitor');
            $bPosted = true;
            $newLatest = DB::table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->first();
            $this->assertGreaterThan($oldLatest->id, $newLatest->id);
            $this->assertSame((int) $oldLatest->balance_after_minor - 7, (int) $newLatest->balance_after_minor);
            DB::setDefaultConnection($original);
            $this->assertSame($oldLatest->id, $source->table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->value('id'), 'The first connection really retains its earlier non-locking RR snapshot.');
            $source->statement('SAVEPOINT qa_stale_chain');
            $draft = $this->draft($actor, $run, 'forged-stale');
            $this->assertRejected(fn () => DB::table('finance_entries')->insert($this->entry($draft, $shared, 7, (int) $oldLatest->balance_after_minor + 7)), 'A forged entry based on the stale snapshot must fail at the chain trigger.');
            $source->statement('ROLLBACK TO SAVEPOINT qa_stale_chain');
            $current = $this->pair($actor, $counter, $shared, $run, 'current');
            $source->commit();
            $aPosted = true;
            $this->assertSame($competing->id, $this->pair($actor, $shared, $counter, $run, 'competitor')->id);
            $this->assertSame($current->id, $this->pair($actor, $counter, $shared, $run, 'current')->id);
            $this->assertSame($entryCount + 4, DB::table('finance_entries')->count());
            $this->assertSame($transactionCount + 2, DB::table('finance_transactions')->count());
            $this->assertSame(2, DB::table('finance_transactions')->whereIn('id', [$competing->id, $current->id])->where('posted', true)->count());
            $this->assertSame(0, (int) DB::table('finance_entries')->whereIn('transaction_id', [$competing->id, $current->id])->sum('amount_minor'));
            foreach ([$shared, $counter] as $wallet) {
                $balance = (int) DB::table('finance_wallets')->where('id', $wallet)->value('balance_minor');
                $this->assertSame($balance, (int) DB::table('finance_entries')->where('wallet_id', $wallet)->orderByDesc('id')->value('balance_after_minor'));
                $this->assertSame($balance, (int) DB::table('finance_entries')->where('wallet_id', $wallet)->sum('amount_minor'));
            }
            $this->assertSame($before, $this->fingerprint());
            fwrite(STDOUT, 'LEDGER_CHAIN_RR '.json_encode(['real_mariadb_connections' => 2, 'stale_nonlocking_snapshot_proven' => true, 'competing_commit_seen_by_chain' => true,
                'stale_entry_rejected' => true, 'balanced_postings_added' => 2, 'entries_added' => 4, 'idempotent_replays' => 2, 'existing_financial_facts_unchanged' => true, 'new_sales' => 0, 'provider_calls' => 0]).PHP_EOL);
        } finally {
            DB::setDefaultConnection($original);
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            try {
                if ($bPosted && ! $aPosted) {
                    DB::setDefaultConnection($alias);
                    try {
                        $this->pair($actor, $counter, $shared, $run, 'current');
                    } catch (\Throwable $failure) {
                        fwrite(STDOUT, 'LEDGER_CHAIN_RR_RECOVERY_FAILED '.json_encode(['run' => $run, 'original_posting_key' => $run.'-competitor', 'required_idempotent_reverse_key' => $run.'-current',
                            'existing_balance_baseline_restored' => false, 'failure_class' => $failure::class]).PHP_EOL);
                        throw $failure;
                    }
                }
            } finally {
                DB::setDefaultConnection($original);
                DB::purge($alias);
                config(['database.connections.'.$alias => null]);
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function test_bulk_entry_chain_rejects_old_id_gaps_explicit_zero_and_a_corrupt_wallet_inside_rollback_only_transaction(): void
    {
        $lock = $this->guard();
        $sqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        try {
            [$actor, $shared] = $this->actors();
            $before = $this->fingerprint();
            $entryCount = DB::table('finance_entries')->count();
            $transactionCount = DB::table('finance_transactions')->count();
            $run = 'qachain-'.bin2hex(random_bytes(6));
            DB::beginTransaction();
            $wallet = DB::table('finance_wallets')->where('id', $shared)->lockForUpdate()->first();
            $balance = (int) $wallet->balance_minor;
            $holeDraft = $this->draft($actor, $run, 'hole');
            DB::statement('SAVEPOINT qa_chain_gap');
            $gap = DB::table('finance_entries')->insertGetId($this->entry($holeDraft, $shared, 1, $balance + 1));
            DB::statement('ROLLBACK TO SAVEPOINT qa_chain_gap');
            $first = $this->draft($actor, $run, 'bulk-first');
            $second = $this->draft($actor, $run, 'bulk-second');
            DB::table('finance_entries')->insert([$this->entry($first, $shared, 1, $balance + 1), $this->entry($second, $shared, 1, $balance + 2)]);
            $this->assertGreaterThan($gap, DB::table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->value('id'));
            $this->assertSame($balance + 2, (int) DB::table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->value('balance_after_minor'));
            $oldIdDraft = $this->draft($actor, $run, 'old-gap');
            $this->assertRejected(fn () => DB::table('finance_entries')->insert($this->entry($oldIdDraft, $shared, 1, $balance + 3, $gap)), 'A globally free explicit ID below the current wallet tail must be rejected.');
            $ignoreDraft = $this->draft($actor, $run, 'ignored-old-gap');
            $this->assertRejected(fn () => DB::table('finance_entries')->insertOrIgnore($this->entry($ignoreDraft, $shared, 1, $balance + 3, $gap)), 'INSERT IGNORE must not bypass the newest-entry ID guard.');
            $this->assertFalse(DB::table('finance_entries')->where('id', $gap)->exists());
            $validBulkDraft = $this->draft($actor, $run, 'atomic-valid-first');
            $invalidBulkDraft = $this->draft($actor, $run, 'atomic-invalid-second');
            $beforeBadBulk = DB::table('finance_entries')->count();
            $beforeBadBulkTail = DB::table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->value('id');
            $this->assertRejected(fn () => DB::table('finance_entries')->insert([$this->entry($validBulkDraft, $shared, 1, $balance + 3), $this->entry($invalidBulkDraft, $shared, 1, $balance + 99)]), 'A bad second bulk row must roll back the valid first row in the same statement.');
            $this->assertSame($beforeBadBulk, DB::table('finance_entries')->count());
            $this->assertSame($beforeBadBulkTail, DB::table('finance_entries')->where('wallet_id', $shared)->orderByDesc('id')->value('id'));
            DB::statement('SET SESSION sql_mode = ?', [implode(',', array_unique(array_filter(explode(',', $sqlMode.',NO_AUTO_VALUE_ON_ZERO'))))]);
            $zeroDraft = $this->draft($actor, $run, 'explicit-zero');
            $this->assertRejected(fn () => DB::table('finance_entries')->insert($this->entry($zeroDraft, $shared, 1, $balance + 3, 0)), 'Explicit zero under NO_AUTO_VALUE_ON_ZERO must be rejected after its final ID is known.');
            DB::statement('SET SESSION sql_mode = ?', [$sqlMode]);
            $this->assertRejected(fn () => DB::table('finance_wallets')->where('id', $shared)->update(['balance_minor' => $balance + 3, 'version' => $wallet->version + 1]), 'The wallet must equal the valid newest entry balance.');
            $this->assertSame(1, DB::table('finance_wallets')->where('id', $shared)->update(['balance_minor' => $balance + 2, 'version' => $wallet->version + 1]));
            DB::rollBack();
            $this->assertSame($entryCount, DB::table('finance_entries')->count());
            $this->assertSame($transactionCount, DB::table('finance_transactions')->count());
            $this->assertSame($before, $this->fingerprint());
            fwrite(STDOUT, 'LEDGER_CHAIN_IDS '.json_encode(['bulk_two_auto_entries_accepted' => true, 'explicit_free_old_gap_rejected' => true, 'explicit_zero_no_auto_mode_rejected' => true,
                'insert_ignore_cannot_bypass_guard' => true, 'invalid_second_bulk_row_rolls_back_whole_statement' => true, 'corrupt_wallet_rejected' => true, 'correct_wallet_accepted' => true, 'all_mutations_rolled_back' => true, 'new_sales' => 0, 'provider_calls' => 0]).PHP_EOL);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET SESSION sql_mode = ?', [$sqlMode]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_posting_guard_uses_committed_legs_instead_of_an_older_balanced_repeatable_read_snapshot(): void
    {
        $lock = $this->guard();
        $original = DB::getDefaultConnection();
        $alias = 'ledger_posting_native_competitor';
        config(['database.connections.'.$alias => config('database.connections.'.$original)]);
        $source = DB::connection($original);
        $run = 'qapostchain-'.bin2hex(random_bytes(6));
        $draft = null;
        $thirdCommitted = $repaired = false;
        try {
            [$actor, $shared, $counter] = $this->actors();
            $walletCountBeforePrepare = DB::table('finance_wallets')->count();
            $extraWallets = [];
            foreach (['scale-agent-2', 'scale-agent-3'] as $login) {
                $owner = User::with('membership.account')->where('login', $login)->firstOrFail();
                $this->assertStringEndsWith('@example.invalid', $owner->email);
                $this->assertSame(AccountType::MainAgent, $owner->membership->account->type);
                $extraWallets[] = DB::transaction(fn (): int => app(Ledger::class)->wallet($owner->membership->account_id, 'voucher', 'IQD', 'external')->id);
            }
            $wallets = [$shared, $counter, ...$extraWallets];
            $this->assertCount(4, array_unique($wallets));
            $createdWallets = DB::table('finance_wallets')->count() - $walletCountBeforePrepare;
            $before = $this->fingerprint();
            $entryCount = DB::table('finance_entries')->count();
            $transactionCount = DB::table('finance_transactions')->count();
            fwrite(STDOUT, 'LEDGER_POSTING_RR_GUARD '.json_encode(['run' => $run, 'database' => 'dananiriq_masalverify', 'qa_external_wallets' => $wallets, 'will_add_posted_transactions' => 2,
                'qa_empty_external_wallets_created' => $createdWallets, 'financial_fingerprint_baseline' => 'After preparing any missing empty QA wallets',
                'will_add_entries' => 8, 'expected_net_existing_balance_change' => 0, 'new_sales' => 0, 'provider_calls' => 0]).PHP_EOL);
            $draft = DB::transaction(function () use ($actor, $run, $shared, $counter): int {
                $transaction = $this->draft($actor, $run, 'draft');
                $this->appendDraftLeg($transaction, $shared, 7);
                $this->appendDraftLeg($transaction, $counter, -7);

                return $transaction;
            });
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->beginTransaction();
            $this->assertSame(2, $source->table('finance_entries')->where('transaction_id', $draft)->count());
            $this->assertSame(0, (int) $source->table('finance_entries')->where('transaction_id', $draft)->sum('amount_minor'));
            DB::setDefaultConnection($alias);
            DB::transaction(fn () => $this->appendDraftLeg($draft, $wallets[2], 7));
            $thirdCommitted = true;
            $this->assertSame(3, DB::table('finance_entries')->where('transaction_id', $draft)->count());
            $this->assertSame(7, (int) DB::table('finance_entries')->where('transaction_id', $draft)->sum('amount_minor'));
            DB::setDefaultConnection($original);
            $this->assertSame(2, $source->table('finance_entries')->where('transaction_id', $draft)->count());
            $this->assertSame(0, (int) $source->table('finance_entries')->where('transaction_id', $draft)->sum('amount_minor'), 'The actual first connection still sees its balanced earlier snapshot.');
            $this->assertRejected(fn () => DB::table('finance_transactions')->where('id', $draft)->update(['posted' => true]), 'Posting must reject the committed third leg despite the stale balanced snapshot.');
            $source->rollBack();
            $reverse = $this->settlePostingProof($actor, $draft, $wallets, $thirdCommitted, $run);
            $repaired = true;
            $this->assertSame($reverse->id, $this->settlePostingProof($actor, $draft, $wallets, $thirdCommitted, $run)->id);
            $this->assertSame($entryCount + 8, DB::table('finance_entries')->count());
            $this->assertSame($transactionCount + 2, DB::table('finance_transactions')->count());
            $this->assertSame(2, DB::table('finance_transactions')->whereIn('id', [$draft, $reverse->id])->where('posted', true)->count());
            foreach ([$draft, $reverse->id] as $transaction) {
                $this->assertSame(4, DB::table('finance_entries')->where('transaction_id', $transaction)->count());
                $this->assertSame(0, (int) DB::table('finance_entries')->where('transaction_id', $transaction)->sum('amount_minor'));
            }
            $this->assertSame($before, $this->fingerprint());
            fwrite(STDOUT, 'LEDGER_POSTING_RR '.json_encode(['real_mariadb_connections' => 2, 'stale_balanced_snapshot_proven' => true, 'third_leg_committed' => true,
                'unbalanced_posting_rejected' => true, 'balanced_postings_added' => 2, 'entries_added' => 8, 'qa_empty_external_wallets_created' => $createdWallets,
                'reverse_replay_same_id' => true, 'all_wallet_balances_restored' => true, 'new_sales' => 0, 'provider_calls' => 0]).PHP_EOL);
        } finally {
            DB::setDefaultConnection($original);
            while ($source->transactionLevel() > 0) {
                $source->rollBack();
            }
            try {
                if ($draft !== null && ! $repaired) {
                    try {
                        $this->settlePostingProof($actor, $draft, $wallets, $thirdCommitted, $run);
                    } catch (\Throwable $failure) {
                        fwrite(STDOUT, 'LEDGER_POSTING_RR_RECOVERY_FAILED '.json_encode(['run' => $run, 'draft_transaction' => $draft, 'third_leg_committed' => $thirdCommitted,
                            'required_idempotent_reverse_key' => $run.'-posting-reverse', 'all_wallet_balances_restored' => false, 'failure_class' => $failure::class]).PHP_EOL);
                        throw $failure;
                    }
                }
            } finally {
                DB::setDefaultConnection($original);
                DB::purge($alias);
                config(['database.connections.'.$alias => null]);
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function test_latest_chain_wallet_validation_remains_indexed_at_retained_half_million_wallet_depth(): void
    {
        $lock = $this->guard();
        try {
            [, $shared] = $this->actors();
            $before = $this->fingerprint();
            $walletGuard = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', 'dananiriq_masalverify')->where('EVENT_OBJECT_TABLE', 'finance_wallets')->where('EVENT_MANIPULATION', 'UPDATE')->where('ACTION_TIMING', 'BEFORE')->first();
            $this->assertNotNull($walletGuard);
            $this->assertStringContainsString('balance_after_minor', strtolower($walletGuard->ACTION_STATEMENT));
            $this->assertDoesNotMatchRegularExpression('/\bsum\s*\(/i', $walletGuard->ACTION_STATEMENT, 'Measure the installed latest-entry guard rather than infer it from a hypothetical SELECT.');
            $times = [];
            for ($attempt = 0; $attempt < 5; $attempt++) {
                DB::beginTransaction();
                try {
                    $started = microtime(true);
                    DB::table('finance_wallets')->where('id', $shared)->update(['version' => DB::raw('version + 1')]);
                    $times[] = round((microtime(true) - $started) * 1000, 3);
                } finally {
                    DB::rollBack();
                }
            }
            $explain = DB::select('EXPLAIN SELECT balance_after_minor FROM finance_entries WHERE wallet_id = ? ORDER BY id DESC LIMIT 1', [$shared]);
            $this->assertNotSame('ALL', $explain[0]->type);
            $this->assertNotNull($explain[0]->key);
            $this->assertSame($before, $this->fingerprint());
            fwrite(STDOUT, 'LEDGER_CHAIN_DEPTH '.json_encode(['shared_wallet_entries' => DB::table('finance_entries')->where('wallet_id', $shared)->count(), 'wallet_update_rollback_ms' => $times,
                'installed_wallet_guard_name' => $walletGuard->TRIGGER_NAME, 'installed_wallet_guard_sha256' => hash('sha256', $walletGuard->ACTION_STATEMENT), 'installed_wallet_guard_reads_balance_after_without_sum' => true,
                'latest_entry_lookup_explain' => $explain, 'existing_financial_facts_unchanged' => true, 'no_capacity_claim_from_microbenchmark' => true]).PHP_EOL);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
