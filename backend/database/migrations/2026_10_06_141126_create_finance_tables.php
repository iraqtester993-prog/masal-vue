<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_wallets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('service', 40);
            $t->string('currency', 3);
            $t->string('kind', 16)->default('account');
            $t->bigInteger('balance_minor')->default(0);
            $t->unsignedBigInteger('held_minor')->default(0);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['account_id', 'service', 'currency', 'kind'], 'finance_wallet_identity');
        });
        Schema::create('finance_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 40);
            $t->string('service', 40);
            $t->string('currency', 3);
            $t->string('reference', 200);
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->boolean('posted')->default(false);
            $t->timestamp('recovery_deadline')->nullable();
            $t->foreignId('reversal_of')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'idempotency_key'], 'finance_transaction_idempotence');
            $t->unique('reversal_of');
        });
        Schema::create('finance_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transaction_id')->constrained('finance_transactions')->restrictOnDelete();
            $t->foreignId('wallet_id')->constrained('finance_wallets')->restrictOnDelete();
            $t->bigInteger('amount_minor');
            $t->bigInteger('balance_after_minor');
            $t->timestamp('created_at');
            $t->unique(['transaction_id', 'wallet_id']);
            $t->index(['wallet_id', 'id']);
        });
        Schema::create('finance_operations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 50);
            $t->string('idempotency_key', 100);
            $t->string('payload_hash', 64);
            $t->json('response')->nullable();
            $t->timestamp('created_at');
            $t->unique(['actor_id', 'idempotency_key'], 'finance_operation_idempotence');
        });
        Schema::create('finance_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 16);
            $t->string('supplier', 160)->default('');
            $t->string('service', 40);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->unsignedBigInteger('paid_minor')->default(0);
            $t->string('reference', 200);
            $t->string('source_type', 50)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('status', 16)->default('unpaid');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'kind'], 'finance_invoice_source');
            $t->index(['account_id', 'status', 'id']);
        });
        Schema::create('finance_funding_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('service', 40);
            $t->string('currency', 3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('purpose', 1000)->default('');
            $t->string('status', 16)->default('pending');
            $t->string('reason', 1000)->default('');
            $t->string('reference', 200)->default('');
            $t->foreignId('transaction_id')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $t->unsignedBigInteger('stock_batch_id')->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['to_account_id', 'created_at']);
            $t->index(['from_account_id', 'status']);
        });
        Schema::create('finance_policy', function (Blueprint $t): void {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('daily_limit')->default(1);
            $t->json('amounts_minor');
            $t->unsignedInteger('recovery_hours')->default(24);
            $t->unsignedInteger('version')->default(1);
        });
        DB::table('finance_policy')->insert(['id' => 1, 'daily_limit' => 1, 'amounts_minor' => json_encode([5000000, 10000000, 15000000, 20000000, 25000000, 30000000]), 'recovery_hours' => 24, 'version' => 1]);
        Schema::create('finance_price_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('changes');
            $t->string('status', 16)->default('pending');
            $t->string('reason', 1000)->default('');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'id']);
        });
        Schema::create('finance_prices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('request_id')->constrained('finance_price_requests')->restrictOnDelete();
            $t->unsignedBigInteger('price_minor');
            $t->string('currency', 3);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['account_id', 'product_id']);
        });
        Schema::create('finance_recoveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transfer_id')->constrained('finance_transactions')->restrictOnDelete();
            $t->foreignId('transaction_id')->constrained('finance_transactions')->restrictOnDelete()->unique();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('amount_minor');
            $t->string('reason', 1000);
            $t->timestamp('created_at');
        });
        $this->ledgerGuards();
    }

    private function ledgerGuards(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER finance_transactions_valid_insert BEFORE INSERT ON finance_transactions BEGIN SELECT CASE WHEN NEW.posted <> 0 THEN RAISE(ABORT, 'Transaction must be posted through balanced entries') END; END");
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_insert BEFORE INSERT ON finance_wallets BEGIN SELECT CASE WHEN NEW.balance_minor <> 0 OR NEW.held_minor <> 0 OR NEW.kind NOT IN ('account', 'external') THEN RAISE(ABORT, 'Wallet must start empty') END; END");
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets BEGIN SELECT CASE WHEN NEW.account_id <> OLD.account_id OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.kind <> OLD.kind OR NEW.version <> OLD.version + 1 OR ABS(NEW.balance_minor) > 999999999999999 OR NEW.held_minor < 0 OR (NEW.kind = 'account' AND NEW.balance_minor < NEW.held_minor) OR NEW.balance_minor <> (SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE wallet_id = OLD.id) THEN RAISE(ABORT, 'Invalid financial wallet balance') END; END");
            foreach (['finance_entries', 'finance_transactions', 'finance_recoveries'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Immutable financial record'); END");
            }
            DB::unprepared("CREATE TRIGGER finance_entries_no_update BEFORE UPDATE ON finance_entries BEGIN SELECT RAISE(ABORT, 'Immutable financial entry'); END");
            DB::unprepared("CREATE TRIGGER finance_recoveries_no_update BEFORE UPDATE ON finance_recoveries BEGIN SELECT RAISE(ABORT, 'Immutable financial recovery'); END");
            DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries BEGIN SELECT CASE WHEN typeof(NEW.amount_minor) <> 'integer' OR typeof(NEW.balance_after_minor) <> 'integer' OR NEW.amount_minor = 0 OR ABS(NEW.amount_minor) > 999999999999999 OR NOT EXISTS (SELECT 1 FROM finance_wallets w JOIN finance_transactions t ON t.id = NEW.transaction_id WHERE w.id = NEW.wallet_id AND w.service = t.service AND w.currency = t.currency AND t.posted = 0) THEN RAISE(ABORT, 'Invalid financial entry') END; END");
            DB::unprepared("CREATE TRIGGER finance_transactions_valid_update BEFORE UPDATE ON finance_transactions BEGIN SELECT CASE WHEN OLD.posted <> 0 OR NEW.posted <> 1 OR NEW.actor_id <> OLD.actor_id OR NEW.kind <> OLD.kind OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.reference <> OLD.reference OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash OR NEW.created_at <> OLD.created_at OR NEW.reversal_of IS NOT OLD.reversal_of OR NEW.recovery_deadline IS NOT OLD.recovery_deadline OR (SELECT COUNT(*) FROM finance_entries WHERE transaction_id = OLD.id) < 2 OR (SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE transaction_id = OLD.id) <> 0 THEN RAISE(ABORT, 'Invalid or unbalanced financial posting') END; END");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER finance_transactions_valid_insert BEFORE INSERT ON finance_transactions FOR EACH ROW BEGIN IF NEW.posted <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction must be posted through balanced entries'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_insert BEFORE INSERT ON finance_wallets FOR EACH ROW BEGIN IF NEW.balance_minor <> 0 OR NEW.held_minor <> 0 OR NEW.kind NOT IN ('account', 'external') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet must start empty'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_wallets_valid_update BEFORE UPDATE ON finance_wallets FOR EACH ROW BEGIN IF NEW.account_id <> OLD.account_id OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.kind <> OLD.kind OR NEW.version <> OLD.version + 1 OR ABS(NEW.balance_minor) > 999999999999999 OR NEW.held_minor < 0 OR (NEW.kind = 'account' AND NEW.balance_minor < NEW.held_minor) OR NEW.balance_minor <> (SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE wallet_id = OLD.id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial wallet balance'; END IF; END");
            foreach (['finance_entries', 'finance_transactions', 'finance_recoveries'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable financial record'");
            }
            DB::unprepared("CREATE TRIGGER finance_entries_no_update BEFORE UPDATE ON finance_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable financial entry'");
            DB::unprepared("CREATE TRIGGER finance_recoveries_no_update BEFORE UPDATE ON finance_recoveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable financial recovery'");
            DB::unprepared("CREATE TRIGGER finance_entries_valid BEFORE INSERT ON finance_entries FOR EACH ROW BEGIN IF NEW.amount_minor = 0 OR ABS(NEW.amount_minor) > 999999999999999 OR NOT EXISTS (SELECT 1 FROM finance_wallets w JOIN finance_transactions t ON t.id = NEW.transaction_id WHERE w.id = NEW.wallet_id AND w.service = t.service AND w.currency = t.currency AND t.posted = 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid financial entry'; END IF; END");
            DB::unprepared("CREATE TRIGGER finance_transactions_valid_update BEFORE UPDATE ON finance_transactions FOR EACH ROW BEGIN IF OLD.posted <> 0 OR NEW.posted <> 1 OR NEW.actor_id <> OLD.actor_id OR NEW.kind <> OLD.kind OR NEW.service <> OLD.service OR NEW.currency <> OLD.currency OR NEW.reference <> OLD.reference OR NEW.idempotency_key <> OLD.idempotency_key OR NEW.payload_hash <> OLD.payload_hash OR NEW.created_at <> OLD.created_at OR NOT (NEW.reversal_of <=> OLD.reversal_of) OR NOT (NEW.recovery_deadline <=> OLD.recovery_deadline) OR (SELECT COUNT(*) FROM finance_entries WHERE transaction_id = OLD.id) < 2 OR (SELECT COALESCE(SUM(amount_minor), 0) FROM finance_entries WHERE transaction_id = OLD.id) <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or unbalanced financial posting'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['finance_recoveries', 'finance_prices', 'finance_price_requests', 'finance_policy', 'finance_funding_requests', 'finance_invoices', 'finance_operations', 'finance_entries', 'finance_transactions', 'finance_wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
