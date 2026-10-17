<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Unsupported funded Topup database.');
        }
        if ($driver === 'mysql') {
            $fresh = app()->bound('masal.fresh_restore_database') && app('masal.fresh_restore_database') === DB::connection();
            $qa = app()->environment('testing') && DB::connection()->getDatabaseName() === 'dananiriq_masalverify'
                && str_starts_with(str_replace('\\', '/', realpath(base_path()) ?: ''), '/home/dananiriq/masal-verification/');
            if (! $fresh && ! $qa) {
                if (! app()->isDownForMaintenance()) {
                    throw new RuntimeException('Funded Topup changes require maintenance and a blocked write gate.');
                }
                DB::transaction(function (): void {
                    $gate = DB::table('runtime_write_gate')->where('id', 1)->lockForUpdate()->first();
                    if (! $gate || ! $gate->blocked) {
                        throw new RuntimeException('Funded Topup changes require a drained, blocked write gate.');
                    }
                });
            }
        }
        if (DB::table('finance_wallets')->where('service', 'topup')->where(fn ($query) => $query->where('balance_minor', '<>', 0)->orWhere('held_minor', '<>', 0))->exists()
            || DB::table('finance_transactions')->where('service', 'topup')->exists()) {
            throw new RuntimeException('Existing unbound Topup wallets contain money or holds. Reconcile them explicitly before enabling funded Topup; no money was changed.');
        }
        Schema::create('topup_wallet_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->unique()->constrained('finance_wallets')->restrictOnDelete();
            $table->foreignId('grant_id')->nullable()->constrained('topup_grants')->restrictOnDelete();
            $table->string('role', 20);
            $table->timestamp('created_at');
            $table->index(['grant_id', 'role'], 'topup_wallet_grant_role');
        });
        Schema::create('topup_finance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained('finance_transactions')->restrictOnDelete();
            $table->foreignId('grant_id')->constrained('topup_grants')->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('digital_connections')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('digital_orders')->restrictOnDelete();
            $table->string('phase', 24);
            $table->string('event_key', 100)->unique();
            $table->timestamp('created_at');
            $table->unique(['order_id', 'phase'], 'topup_order_finance_phase');
            $table->index(['grant_id', 'phase', 'id'], 'topup_grant_finance_history');
        });
        $triggers = DB::getDriverName() === 'sqlite' ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'digital_orders'") : [];
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->string('budget_basis', 24)->default('legacy_main');
            $table->unsignedBigInteger('budget_wallet_id')->nullable();
            $table->foreign('budget_wallet_id')->references('wallet_id')->on('topup_wallet_bindings')->restrictOnDelete();
        });
        foreach ($triggers as $trigger) {
            if (! DB::selectOne("SELECT name FROM sqlite_master WHERE type='trigger' AND name=?", [$trigger->name])) {
                DB::unprepared($trigger->sql);
            }
        }
        $validBinding = "EXISTS (SELECT 1 FROM finance_wallets w JOIN accounts a ON a.id=w.account_id LEFT JOIN topup_grants g ON g.id=NEW.grant_id WHERE w.id=NEW.wallet_id AND w.service='topup' AND w.currency='IQD' AND ((NEW.role='clearing' AND NEW.grant_id IS NULL AND a.type='system' AND w.kind='external') OR (NEW.role='main' AND a.type='main_agent' AND w.kind='account' AND g.target_account_id=a.id AND g.main_account_id=a.id AND g.connection_id IS NOT NULL) OR (NEW.role='margin' AND a.type='main_agent' AND w.kind='external' AND g.target_account_id=a.id AND g.main_account_id=a.id AND g.connection_id IS NOT NULL) OR (NEW.role='point' AND a.type='pos' AND w.kind='account' AND g.connection_id IS NOT NULL AND EXISTS (SELECT 1 FROM account_closure c WHERE c.ancestor_id=g.target_account_id AND c.descendant_id=a.id AND c.depth>0))))";
        $validEvent = "EXISTS (SELECT 1 FROM finance_transactions t JOIN topup_grants g ON g.id=NEW.grant_id WHERE t.id=NEW.transaction_id AND t.posted=1 AND t.service='topup' AND t.currency='IQD' AND EXISTS (SELECT 1 FROM digital_connections c WHERE c.id=NEW.connection_id AND c.provider='topup'))";
        $validBudget = "((NEW.budget_basis='legacy_main' AND NEW.budget_wallet_id IS NULL) OR (NEW.budget_basis='point_budget_v1' AND NEW.provider='topup' AND NEW.admin_price_minor IS NOT NULL AND NEW.budget_wallet_id IS NOT NULL AND NEW.topup_grant_id IS NOT NULL AND NEW.admin_price_minor>0 AND NEW.quoted_retail_minor>=NEW.admin_price_minor AND EXISTS (SELECT 1 FROM topup_wallet_bindings b JOIN finance_wallets w ON w.id=b.wallet_id WHERE b.wallet_id=NEW.budget_wallet_id AND b.grant_id=NEW.topup_grant_id AND b.role='point' AND w.account_id=NEW.account_id)))";
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER topup_binding_valid BEFORE INSERT ON topup_wallet_bindings WHEN NOT ({$validBinding}) BEGIN SELECT RAISE(ABORT, 'Invalid Topup wallet binding'); END");
            DB::unprepared("CREATE TRIGGER topup_event_valid BEFORE INSERT ON topup_finance_events WHEN NOT ({$validEvent}) BEGIN SELECT RAISE(ABORT, 'Invalid Topup financial event'); END");
            DB::unprepared("CREATE TRIGGER digital_topup_budget_valid BEFORE INSERT ON digital_orders WHEN NOT ({$validBudget}) BEGIN SELECT RAISE(ABORT, 'Invalid Topup funded budget'); END");
            DB::unprepared("CREATE TRIGGER digital_topup_budget_immutable BEFORE UPDATE ON digital_orders WHEN NEW.budget_basis IS NOT OLD.budget_basis OR NEW.budget_wallet_id IS NOT OLD.budget_wallet_id BEGIN SELECT RAISE(ABORT, 'Topup funding basis is immutable'); END");
        } else {
            DB::unprepared("CREATE TRIGGER topup_binding_valid BEFORE INSERT ON topup_wallet_bindings FOR EACH ROW BEGIN IF NOT ({$validBinding}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Topup wallet binding'; END IF; END");
            DB::unprepared("CREATE TRIGGER topup_event_valid BEFORE INSERT ON topup_finance_events FOR EACH ROW BEGIN IF NOT ({$validEvent}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Topup financial event'; END IF; END");
            DB::unprepared("CREATE TRIGGER digital_topup_budget_valid BEFORE INSERT ON digital_orders FOR EACH ROW BEGIN IF NOT ({$validBudget}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Invalid Topup funded budget'; END IF; END");
            DB::unprepared("CREATE TRIGGER digital_topup_budget_immutable BEFORE UPDATE ON digital_orders FOR EACH ROW BEGIN IF NOT (NEW.budget_basis <=> OLD.budget_basis) OR NOT (NEW.budget_wallet_id <=> OLD.budget_wallet_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Topup funding basis is immutable'; END IF; END");
        }
        foreach (['topup_wallet_bindings', 'topup_finance_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_no_'.$operation;
                DB::unprepared($driver === 'sqlite'
                    ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Topup financial metadata is immutable'); END"
                    : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Topup financial metadata is immutable'; END");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Funded Topup wallets require restoring the complete pre-upgrade backup; financial history cannot be discarded.');
    }
};
