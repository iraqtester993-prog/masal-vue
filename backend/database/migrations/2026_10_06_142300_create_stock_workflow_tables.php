<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('catalog_providers')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('order_sources')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('city', 80);
            $table->string('status', 32)->default('pending');
            $table->string('preview_hash', 64);
            $table->longText('payload');
            $table->json('summary');
            $table->boolean('excluded_confirmed')->default(false);
            $table->text('reason')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('rejected');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status', 'id']);
        });
        Schema::create('stock_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('stock_orders')->restrictOnDelete();
            $table->string('line_key', 100);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->foreignId('source_id')->constrained('order_sources')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('finance_invoices')->restrictOnDelete();
            $table->string('currency', 3);
            $table->string('file_name', 200);
            $table->string('city', 80);
            $table->string('supplier', 150);
            $table->text('notes')->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('expenses_minor')->default(0);
            $table->unsignedBigInteger('cost_total_minor');
            $table->unsignedBigInteger('load_price_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32)->default('Loaded');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('replacement_claim_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['order_id', 'line_key']);
            $table->index(['account_id', 'product_id', 'status']);
        });
        Schema::create('stock_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->restrictOnDelete();
            $table->string('serial', 190);
            $table->string('serial_hash', 64);
            $table->string('pin_hash', 64)->unique();
            $table->longText('secret');
            $table->date('expiry');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('credit_minor');
            $table->boolean('credit_held')->default(false);
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('claim_id')->nullable();
            $table->unsignedBigInteger('adjustment_id')->nullable();
            $table->string('status', 32)->default('Available');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'serial_hash']);
            $table->index(['account_id', 'product_id', 'status', 'expiry', 'id'], 'stock_available_pick');
            $table->index(['batch_id', 'status']);
        });
        Schema::create('stock_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('card_ids');
            $table->text('reason');
            $table->string('purpose', 32)->default('damage');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('credit_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->foreignId('replacement_batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });
        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 32);
            $table->json('card_ids');
            $table->text('reason');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('credit_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->unsignedBigInteger('invoice_amount_minor')->default(0);
            $table->foreignId('transaction_id')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $table->string('status', 32)->default('cancelled');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained('stock_claims')->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('card_ids');
            $table->json('original');
            $table->unsignedBigInteger('debit_minor')->default(0);
            $table->text('reason');
            $table->text('review_reason')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('finance_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['stock_withdrawals', 'stock_adjustments', 'stock_claims', 'stock_cards', 'stock_batches', 'stock_orders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
