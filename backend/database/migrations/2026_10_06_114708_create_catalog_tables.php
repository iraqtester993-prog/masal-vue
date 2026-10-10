<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalog_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160)->unique();
            $table->string('supplier', 160);
            $table->string('connection', 16);
            $table->string('status', 16)->default('active');
            $table->string('image_path')->nullable();
            $table->string('image_mime', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('legacy_reference', 64)->nullable()->unique();
            $table->foreignId('provider_id')->constrained('catalog_providers')->restrictOnDelete();
            $table->unique(['provider_id', 'name']);
            $table->string('kind', 16);
            $table->decimal('face_value', 16, 2)->nullable();
            $table->string('currency', 3);
            $table->decimal('minimum_price', 16, 2)->nullable();
            $table->string('daily_limit_type', 16);
            $table->unsignedInteger('daily_quantity')->nullable();
            $table->decimal('daily_amount', 16, 2)->nullable();
            $table->json('field_policy');
            $table->json('extra_fields');
            $table->json('import_codes');
            $table->unsignedInteger('display_order')->default(0);
            $table->string('receipt_language', 16)->default('');
            $table->unsignedSmallInteger('receipt_width')->default(80);
            $table->string('receipt_header', 1000)->default('');
            $table->string('receipt_footer', 1000)->default('');
            $table->json('allowed_cities');
            $table->string('status', 16)->default('active');
            $table->string('image_path')->nullable();
            $table->string('image_mime', 32)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['display_order', 'id']);
            $table->index(['provider_id', 'status']);
        });
        Schema::create('catalog_account_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('authority_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->unique(['target_account_id', 'authority_account_id'], 'catalog_rules_target_authority_unique');
        });
        Schema::create('catalog_rule_products', function (Blueprint $table): void {
            $table->foreignId('rule_id')->constrained('catalog_account_rules')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->primary(['rule_id', 'product_id']);
        });
        Schema::create('catalog_meta', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedInteger('version')->default(1);
        });
        DB::table('catalog_meta')->insert(['id' => 1, 'version' => 1]);
        Schema::create('catalog_preferences', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->json('hidden_columns');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['catalog_preferences', 'catalog_rule_products', 'catalog_account_rules', 'catalog_products', 'catalog_providers', 'catalog_meta'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
