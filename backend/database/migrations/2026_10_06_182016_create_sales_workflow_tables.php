<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_policy', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->json('settings');
            $t->json('policy');
            $t->unsignedInteger('version')->default(1);
        });
        DB::table('sales_policy')->insert(['id' => 1, 'settings' => json_encode(['sales_enabled' => true, 'printing_enabled' => true, 'velocity_seconds' => 3, 'provider_daily_minor' => ['IQD' => 100000000, 'USD' => null], 'min_app_version' => '1.0.0', 'min_os_version' => null, 'reprint_limit' => 5]), 'policy' => json_encode(['failed_retries' => 2, 'max_cards' => 10, 'interval_seconds' => 5, 'daily_cards' => 0, 'daily_mode' => 'account', 'daily_product_mode' => 'all', 'daily_products' => []]), 'version' => 1]);
        Schema::create('sales_print_rules', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 160);
            $t->json('targets');
            $t->json('policy');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('sales_limits', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('authority_account_id')->constrained('accounts')->restrictOnDelete();
            $t->unsignedInteger('max_cards')->nullable();
            $t->unsignedInteger('daily_quantity')->nullable();
            $t->unsignedBigInteger('daily_amount_minor')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['account_id', 'product_id', 'authority_account_id'], 'sales_limits_unique_authority');
        });
        Schema::create('sales_device_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('session_hash', 64)->unique();
            $t->unsignedInteger('session_version');
            $t->string('serial', 190)->nullable();
            $t->string('app_version', 30);
            $t->string('os_version', 30)->nullable();
            $t->timestamp('last_seen_at');
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('sales_receipt_layouts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('scope_key', 100)->unique();
            $t->json('layout');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('sales', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('main_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $t->foreignId('provider_id')->constrained('catalog_providers')->restrictOnDelete();
            $t->foreignId('transaction_id')->nullable()->unique()->constrained('finance_transactions')->restrictOnDelete();
            $t->string('currency', 3);
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('price_minor');
            $t->unsignedBigInteger('total_minor');
            $t->unsignedBigInteger('retail_price_minor');
            $t->unsignedBigInteger('retail_total_minor');
            $t->unsignedBigInteger('credit_minor');
            $t->unsignedBigInteger('cost_minor');
            $t->unsignedBigInteger('load_cost_minor');
            $t->unsignedInteger('price_version');
            $t->string('status', 32)->default('Reserved');
            $t->boolean('print_pending')->default(false);
            $t->boolean('failure_retry')->default(false);
            $t->unsignedInteger('failed_retry_count')->default(0);
            $t->unsignedInteger('reprints')->default(0);
            $t->unsignedBigInteger('reprint_request_id')->nullable();
            $t->text('failure_reason')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('exposed_at')->nullable();
            $t->timestamp('print_started_at')->nullable();
            $t->timestamp('first_printed_at')->nullable();
            $t->string('delivery_channel', 30)->nullable();
            $t->string('delivery_reference', 200)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['account_id', 'status', 'created_at']);
            $t->index(['main_account_id', 'issued_at', 'product_id']);
        });
        Schema::create('sales_print_attempts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('status', 20)->default('pending');
            $t->text('reason')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('version')->default(1);
        });
        Schema::create('sales_reprint_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('previous_status', 32);
            $t->text('reason');
            $t->text('failure_reason')->nullable();
            $t->string('status', 20)->default('pending');
            $t->json('history');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
            $t->index(['recipient_id', 'status']);
        });
        $fields = ['account_id', 'main_account_id', 'creator_id', 'product_id', 'provider_id', 'currency', 'quantity', 'price_minor', 'total_minor', 'retail_price_minor', 'retail_total_minor', 'credit_minor', 'cost_minor', 'load_cost_minor', 'price_version'];
        if (DB::getDriverName() === 'sqlite') {
            $changed = implode(' OR ', array_map(fn (string $field): string => 'NEW.'.$field.' IS NOT OLD.'.$field, $fields));
            $changed .= ' OR (OLD.transaction_id IS NOT NULL AND NEW.transaction_id IS NOT OLD.transaction_id) OR (OLD.issued_at IS NOT NULL AND NEW.issued_at IS NOT OLD.issued_at) OR (OLD.first_printed_at IS NOT NULL AND NEW.first_printed_at IS NOT OLD.first_printed_at) OR (OLD.exposed_at IS NOT NULL AND NEW.exposed_at IS NOT OLD.exposed_at)';
            DB::unprepared("CREATE TRIGGER sales_financial_immutable BEFORE UPDATE ON sales WHEN $changed BEGIN SELECT RAISE(ABORT, 'Sales financial values are immutable'); END");
            DB::unprepared("CREATE TRIGGER sales_delete_immutable BEFORE DELETE ON sales BEGIN SELECT RAISE(ABORT, 'Sales are immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            $changed = implode(' OR ', array_map(fn (string $field): string => 'NOT (NEW.'.$field.' <=> OLD.'.$field.')', $fields));
            $changed .= ' OR (OLD.transaction_id IS NOT NULL AND NOT (NEW.transaction_id <=> OLD.transaction_id)) OR (OLD.issued_at IS NOT NULL AND NOT (NEW.issued_at <=> OLD.issued_at)) OR (OLD.first_printed_at IS NOT NULL AND NOT (NEW.first_printed_at <=> OLD.first_printed_at)) OR (OLD.exposed_at IS NOT NULL AND NOT (NEW.exposed_at <=> OLD.exposed_at))';
            DB::unprepared("CREATE TRIGGER sales_financial_immutable BEFORE UPDATE ON sales FOR EACH ROW BEGIN IF $changed THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sales financial values are immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER sales_delete_immutable BEFORE DELETE ON sales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Sales are immutable'");
        }
    }

    public function down(): void
    {
        foreach (['sales_reprint_requests', 'sales_print_attempts', 'sales', 'sales_receipt_layouts', 'sales_device_sessions', 'sales_limits', 'sales_print_rules', 'sales_policy'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
