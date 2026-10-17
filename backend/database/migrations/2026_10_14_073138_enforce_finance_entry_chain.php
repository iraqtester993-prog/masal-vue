<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changeGuards(true);
    }

    public function down(): void
    {
        $this->changeGuards(false);
    }

    private function changeGuards(bool $chain): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported financial guard database.');
        }
        if ($driver === 'mysql') {
            $this->assertQuiesced(DB::connection());
        }
        $replace = function () use ($chain, $driver): void {
            $this->validateHistory();
            foreach (['finance_wallets_valid_update', 'finance_entries_valid', 'finance_entries_append', 'finance_transactions_valid_update'] as $trigger) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
            }
            $chain ? $this->chainGuards($driver) : $this->legacyGuards($driver);
        };
        if ($driver === 'sqlite') {
            DB::transaction($replace);
        } else {
            $replace();
        }
    }

    private function assertQuiesced(Connection $connection): void
    {
        if (app()->bound('masal.fresh_restore_database') && app('masal.fresh_restore_database') === $connection) {
            return;
        }
        $root = str_replace('\\', '/', realpath(base_path()) ?: '');
        if (app()->environment('testing') && $connection->getDatabaseName() === 'dananiriq_masalverify' && str_starts_with($root, '/home/dananiriq/masal-verification/')) {
            return;
        }
        if (! app()->isDownForMaintenance() || ! Schema::hasTable('runtime_write_gate')) {
            throw new RuntimeException('Financial guard changes require maintenance and a blocked write gate.');
        }
        $connection->transaction(function () use ($connection): void {
            $gate = $connection->table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
            if (! $gate || ! $gate->blocked) {
                throw new RuntimeException('Financial guard changes require a drained, blocked write gate.');
            }
        });
    }

    private function validateHistory(): void
    {
        $sums = DB::table('finance_entries')->selectRaw('wallet_id, SUM(amount_minor) AS total')->groupBy('wallet_id');
        $history = DB::table('finance_entries')->selectRaw('id, balance_after_minor, SUM(amount_minor) OVER (PARTITION BY wallet_id ORDER BY id ROWS UNBOUNDED PRECEDING) AS expected');
        if (DB::table('finance_entries')->whereRaw('id <= 0 OR amount_minor = 0 OR ABS(amount_minor) > 999999999999999 OR ABS(balance_after_minor) > 999999999999999')->exists()
            || DB::table('finance_wallets as w')->leftJoinSub($sums, 'e', 'e.wallet_id', '=', 'w.id')->whereRaw('w.balance_minor <> COALESCE(e.total, 0)')->exists()
            || DB::query()->fromSub($history, 'history')->whereColumn('balance_after_minor', '<>', 'expected')->exists()) {
            throw new RuntimeException('Financial history does not match wallet balances and ordered entry balances; no guards were changed.');
        }
        $postings = DB::table('finance_entries')->selectRaw('transaction_id, COUNT(*) AS legs, SUM(amount_minor) AS total')->groupBy('transaction_id');
        if (DB::table('finance_transactions as t')->leftJoinSub($postings, 'e', 'e.transaction_id', '=', 't.id')->where(fn ($query) => $query->where('t.posted', '<>', 1)->orWhereRaw('COALESCE(e.legs, 0) < 2 OR COALESCE(e.total, 0) <> 0'))->exists()
            || DB::table('finance_entries as e')->join('finance_wallets as w', 'w.id', '=', 'e.wallet_id')->join('finance_transactions as t', 't.id', '=', 'e.transaction_id')->where(fn ($query) => $query->whereColumn('w.service', '<>', 't.service')->orWhereColumn('w.currency', '<>', 't.currency'))->exists()) {
            throw new RuntimeException('Financial postings are incomplete, unbalanced or incompatible; no guards were changed.');
        }
    }

    private function invalidWallet(string $balance): string
    {
        return "NEW.account_id <> OLD.account_id OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.kind <> OLD.kind OR NEW.version <> OLD.version + 1 OR ABS(NEW.balance_minor) > 999999999999999 OR NEW.held_minor < 0 OR (NEW.kind = 'account' AND NEW.balance_minor < NEW.held_minor) OR NEW.balance_minor <> {$balance}";
    }

    private function invalidEntry(): string
    {
        return 'NEW.amount_minor = 0 OR ABS(NEW.amount_minor) > 999999999999999 OR NOT EXISTS (SELECT 1 FROM finance_wallets w JOIN finance_transactions t ON t.id = NEW.transaction_id WHERE w.id = NEW.wallet_id AND w.service = t.service AND w.currency = t.currency AND t.posted = 0)';
    }

    private function invalidPosting(string $driver, string $legs, string $sum): string
    {
        $nullable = implode(' OR ', array_map(fn ($field) => $driver === 'mysql' ? "NOT (NEW.{$field} <=> OLD.{$field})" : "NEW.{$field} IS NOT OLD.{$field}", ['reversal_of', 'recovery_deadline']));

        return "OLD.posted <> 0 OR NEW.posted <> 1 OR NEW.actor_id <> OLD.actor_id OR NEW.kind <> OLD.kind OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.reference <> OLD.reference OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash OR NEW.created_at <> OLD.created_at OR {$nullable} OR {$legs} < 2 OR {$sum} <> 0";
    }

    private function postingGuard(string $driver, bool $current): void
    {
        if ($current && $driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER finance_transactions_valid_update BEFORE UPDATE ON finance_transactions FOR EACH ROW BEGIN DECLARE entry_count BIGINT DEFAULT 0; DECLARE entry_sum DECIMAL(65, 0) DEFAULT 0; SELECT COUNT(*), COALESCE(SUM(amount_minor), 0) INTO entry_count, entry_sum FROM finance_entries WHERE transaction_id = OLD.id LOCK IN SHARE MODE; IF {$this->invalidPosting($driver, 'entry_count', 'entry_sum')} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or unbalanced financial posting'; END IF; END");

            return;
        }
        $condition = $this->invalidPosting($driver, '(SELECT COUNT(*) FROM finance_entries WHERE transaction_id = OLD.id)', '(SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE transaction_id = OLD.id)');
        DB::unprepared($driver === 'sqlite'
            ? "CREATE TRIGGER finance_transactions_valid_update BEFORE UPDATE ON finance_transactions BEGIN SELECT CASE WHEN {$condition} THEN RAISE(ABORT, 'Invalid or unbalanced financial posting') END; END"
            : "CREATE TRIGGER finance_transactions_valid_update BEFORE UPDATE ON finance_transactions FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or unbalanced financial posting'; END IF; END");
    }

    private function chainGuards(string $driver): void
    {
        $this->postingGuard($driver, true);
        if ($driver === 'sqlite') {
            $balance = '(SELECT COALESCE((SELECT balance_after_minor FROM finance_entries WHERE wallet_id = OLD.id ORDER BY id DESC LIMIT 1), 0))';
            $previous = '(SELECT COALESCE((SELECT balance_after_minor FROM finance_entries WHERE wallet_id = NEW.wallet_id ORDER BY id DESC LIMIT 1), 0))';
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets BEGIN SELECT CASE WHEN {$this->invalidWallet($balance)} THEN RAISE(ABORT, 'Invalid financial wallet balance') END; END");
            DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries BEGIN SELECT CASE WHEN typeof(NEW.amount_minor) <> 'integer' OR typeof(NEW.balance_after_minor) <> 'integer' OR {$this->invalidEntry()} OR ABS(NEW.balance_after_minor) > 999999999999999 OR NEW.balance_after_minor <> {$previous} + NEW.amount_minor THEN RAISE(ABORT, 'Invalid financial entry chain') END; END");
            DB::unprepared("CREATE TRIGGER finance_entries_append AFTER INSERT ON finance_entries BEGIN SELECT CASE WHEN NEW.id <= 0 OR NEW.id <> (SELECT id FROM finance_entries WHERE wallet_id = NEW.wallet_id ORDER BY id DESC LIMIT 1) THEN RAISE(ABORT, 'Financial entries must append in order') END; END");

            return;
        }
        DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets FOR EACH ROW BEGIN DECLARE journal_balance BIGINT DEFAULT 0; DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END; SELECT balance_after_minor INTO journal_balance FROM finance_entries WHERE wallet_id = OLD.id ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE; IF {$this->invalidWallet('journal_balance')} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial wallet balance'; END IF; END");
        DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries FOR EACH ROW BEGIN
            DECLARE wallet_identity BIGINT DEFAULT NULL; DECLARE wallet_service VARCHAR(40); DECLARE wallet_currency VARCHAR(3);
            DECLARE transaction_posted INTEGER DEFAULT NULL; DECLARE transaction_service VARCHAR(40); DECLARE transaction_currency VARCHAR(3);
            DECLARE previous_balance BIGINT DEFAULT 0; DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;
            SELECT id, service, currency INTO wallet_identity, wallet_service, wallet_currency FROM finance_wallets WHERE id = NEW.wallet_id FOR UPDATE;
            SELECT posted, service, currency INTO transaction_posted, transaction_service, transaction_currency FROM finance_transactions WHERE id = NEW.transaction_id LOCK IN SHARE MODE;
            SELECT balance_after_minor INTO previous_balance FROM finance_entries WHERE wallet_id = NEW.wallet_id ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE;
            IF wallet_identity IS NULL OR transaction_posted IS NULL OR transaction_posted <> 0 OR wallet_service <> transaction_service OR wallet_currency <> transaction_currency OR NEW.amount_minor = 0 OR ABS(NEW.amount_minor) > 999999999999999 OR ABS(NEW.balance_after_minor) > 999999999999999 OR NEW.balance_after_minor <> previous_balance + NEW.amount_minor THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial entry chain'; END IF; END");
        DB::unprepared("CREATE TRIGGER finance_entries_append AFTER INSERT ON finance_entries FOR EACH ROW BEGIN DECLARE last_identity BIGINT DEFAULT NULL; SELECT id INTO last_identity FROM finance_entries WHERE wallet_id = NEW.wallet_id ORDER BY id DESC LIMIT 1 LOCK IN SHARE MODE; IF NEW.id <= 0 OR NEW.id <> last_identity THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial entries must append in order'; END IF; END");
    }

    private function legacyGuards(string $driver): void
    {
        $this->postingGuard($driver, false);
        $balance = '(SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE wallet_id = OLD.id)';
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets BEGIN SELECT CASE WHEN {$this->invalidWallet($balance)} THEN RAISE(ABORT, 'Invalid financial wallet balance') END; END");
            DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries BEGIN SELECT CASE WHEN typeof(NEW.amount_minor) <> 'integer' OR typeof(NEW.balance_after_minor) <> 'integer' OR {$this->invalidEntry()} THEN RAISE(ABORT, 'Invalid financial entry') END; END");
        } else {
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets FOR EACH ROW BEGIN IF {$this->invalidWallet($balance)} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial wallet balance'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries FOR EACH ROW BEGIN IF {$this->invalidEntry()} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial entry'; END IF; END");
        }
    }
};
