<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_connections', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('provider_id')->constrained('catalog_providers')->restrictOnDelete();
            $t->string('provider', 16);
            $t->text('credential')->nullable();
            $t->boolean('active')->default(false);
            $t->unsignedBigInteger('company_balance_minor')->nullable();
            $t->timestamp('balance_updated_at')->nullable();
            $t->json('bein_provinces')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['account_id', 'provider'], 'digital_connection_identity');
        });
        Schema::create('digital_catalog_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('connection_id')->constrained('digital_connections')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('credential_hash', 64);
            $t->json('catalog');
            $t->json('bein_provinces')->nullable();
            $t->timestamp('created_at');
            $t->index(['connection_id', 'created_at']);
        });
        Schema::create('digital_offers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('connection_id')->constrained('digital_connections')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('catalog_snapshot_id')->nullable()->constrained('digital_catalog_snapshots')->restrictOnDelete();
            $t->string('remote_id', 100)->nullable();
            $t->string('remote_key', 190)->nullable();
            $t->string('remote_name', 200)->nullable();
            $t->string('province_id', 30)->nullable();
            $t->string('province_name', 100)->nullable();
            $t->string('bein_province_id', 30)->nullable();
            $t->string('package_type', 16)->default('standard');
            $t->string('type', 16)->default('voucher');
            $t->unsignedBigInteger('cost_minor')->nullable();
            $t->unsignedBigInteger('retail_minor');
            $t->boolean('active')->default(true);
            $t->boolean('listed')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['connection_id', 'product_id'], 'digital_offer_product');
            $t->unique(['connection_id', 'remote_key'], 'digital_offer_remote');
        });
        Schema::create('digital_grants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('connection_id')->constrained('digital_connections')->restrictOnDelete();
            $t->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('target_account_id')->constrained('accounts')->restrictOnDelete();
            $t->json('offer_ids');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['connection_id', 'target_account_id'], 'digital_grant_target');
        });
        Schema::create('digital_orders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('connection_id')->constrained('digital_connections')->restrictOnDelete();
            $t->foreignId('offer_id')->constrained('digital_offers')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('main_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->string('provider', 16);
            $t->string('request_id', 100)->unique();
            $t->string('request_hash', 64);
            $t->string('remote_id', 100);
            $t->string('province_id', 30)->nullable();
            $t->string('type', 16);
            $t->string('package_type', 16);
            $t->string('mobile', 16)->nullable();
            $t->text('subscriber')->nullable();
            $t->text('provider_hold')->nullable();
            $t->text('provider_evidence')->nullable();
            $t->text('provider_purchase_response')->nullable();
            $t->timestamp('provider_purchase_started_at')->nullable();
            $t->unsignedInteger('offer_version');
            $t->unsignedBigInteger('quoted_cost_minor');
            $t->unsignedBigInteger('quoted_retail_minor');
            $t->unsignedBigInteger('actual_cost_minor')->nullable();
            $t->unsignedBigInteger('actual_retail_minor')->nullable();
            $t->string('cost_basis', 24)->nullable();
            $t->string('status', 16)->default('pending');
            $t->boolean('reservation_active')->default(true);
            $t->string('company_transaction_id', 190)->nullable();
            $t->string('receipt_ref', 190)->nullable();
            $t->text('receipt')->nullable();
            $t->string('message', 500)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('refunded_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['connection_id', 'company_transaction_id'], 'digital_company_transaction');
            $t->index(['account_id', 'status', 'created_at']);
            $t->index(['main_account_id', 'provider', 'created_at']);
        });
        Schema::create('digital_provider_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('order_id')->constrained('digital_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('dispatch_key', 190)->unique();
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('active_order_id')->nullable()->unique();
            $t->string('operation', 16);
            $t->string('status', 16)->default('dispatching');
            $t->string('response_hash', 64)->nullable();
            $t->string('result_status', 16)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
        });
        $driver = DB::getDriverName();
        foreach (['digital_orders', 'digital_catalog_snapshots', 'digital_provider_attempts'] as $table) {
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'Digital history is immutable'); END");
            } elseif ($driver === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Digital history is immutable'");
            }
        }
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER digital_snapshots_no_update BEFORE UPDATE ON digital_catalog_snapshots BEGIN SELECT RAISE(ABORT, 'Provider catalogue snapshot is immutable'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER digital_snapshots_no_update BEFORE UPDATE ON digital_catalog_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider catalogue snapshot is immutable'");
        }
        $invalid = "NEW.provider NOT IN ('rabiaa','topup') OR (NEW.cost_basis IS NOT NULL AND NEW.cost_basis NOT IN ('provider_response','catalog_snapshot')) OR NEW.status NOT IN ('pending','review','succeeded','failed','refunded') OR NEW.version < 1 OR NEW.offer_version < 1 OR NEW.quoted_cost_minor <= 0 OR NEW.quoted_retail_minor <= 0 OR NEW.quoted_cost_minor > 10000000000 OR NEW.quoted_retail_minor > 10000000000 OR (NEW.actual_cost_minor IS NOT NULL AND (NEW.actual_cost_minor <= 0 OR NEW.actual_cost_minor > 10000000000)) OR (NEW.actual_retail_minor IS NOT NULL AND NEW.actual_retail_minor <> NEW.quoted_retail_minor) OR (NEW.status IN ('pending','review') AND NEW.reservation_active <> 1) OR (NEW.status IN ('succeeded','failed','refunded') AND NEW.reservation_active <> 0) OR (NEW.status IN ('succeeded','refunded') AND (NEW.cost_basis IS NULL OR NEW.actual_cost_minor IS NULL OR NEW.actual_retail_minor IS NULL OR NEW.company_transaction_id IS NULL OR NEW.receipt_ref IS NULL OR NEW.resolved_at IS NULL)) OR (NEW.status = 'refunded' AND NEW.refunded_at IS NULL)";
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = 'digital_orders_valid_'.strtolower($event);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON digital_orders WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Digital monetary state is invalid'); END");
            } elseif ($driver === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON digital_orders FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Digital monetary state is invalid'; END IF; END");
            }
        }
        $columns = ['connection_id', 'offer_id', 'account_id', 'main_account_id', 'creator_id', 'product_id', 'provider', 'request_id', 'request_hash', 'remote_id', 'province_id', 'type', 'package_type', 'mobile', 'subscriber', 'offer_version', 'quoted_cost_minor', 'quoted_retail_minor', 'created_at'];
        $immutable = array_map(fn (string $column): string => $driver === 'mysql' ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}", $columns);
        foreach (['company_transaction_id', 'receipt_ref', 'actual_cost_minor', 'actual_retail_minor', 'cost_basis', 'resolved_at', 'refunded_at', 'receipt', 'provider_hold', 'provider_evidence', 'provider_purchase_response', 'provider_purchase_started_at'] as $column) {
            $changed = $driver === 'mysql' ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}";
            $immutable[] = "(OLD.{$column} IS NOT NULL AND {$changed})";
        }
        $immutable[] = "(OLD.status IN ('succeeded','failed','refunded') AND NEW.status <> OLD.status AND NOT (OLD.status = 'succeeded' AND NEW.status = 'refunded'))";
        $condition = implode(' OR ', $immutable);
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER digital_orders_immutable_identity BEFORE UPDATE ON digital_orders WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Digital financial identity is immutable'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER digital_orders_immutable_identity BEFORE UPDATE ON digital_orders FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Digital financial identity is immutable'; END IF; END");
        }
        $attemptColumns = ['order_id', 'actor_id', 'dispatch_key', 'payload_hash', 'operation', 'started_at'];
        $attemptChanged = array_map(fn (string $column): string => $driver === 'mysql' ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}", $attemptColumns);
        foreach (['active_order_id', 'status', 'response_hash', 'result_status', 'finished_at'] as $column) {
            $changed = $driver === 'mysql' ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}";
            $attemptChanged[] = "(OLD.finished_at IS NOT NULL AND {$changed})";
        }
        $condition = implode(' OR ', $attemptChanged);
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER digital_attempts_immutable BEFORE UPDATE ON digital_provider_attempts WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Provider attempt identity and final result are immutable'); END");
        } elseif ($driver === 'mysql') {
            DB::unprepared("CREATE TRIGGER digital_attempts_immutable BEFORE UPDATE ON digital_provider_attempts FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Provider attempt identity and final result are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['digital_provider_attempts', 'digital_orders', 'digital_grants', 'digital_offers', 'digital_catalog_snapshots', 'digital_connections'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
