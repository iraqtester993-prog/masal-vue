<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_cities', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        Schema::create('pos_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('normalized_name', 160)->unique();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        foreach (['متجر', 'سوبر ماركت', 'مطعم'] as $name) {
            DB::table('pos_types')->insert(['name' => $name, 'normalized_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('order_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('provider_id')->constrained('catalog_providers')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('normalized_name', 150);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['network_account_id', 'provider_id', 'normalized_name'], 'order_sources_network_provider_name_unique');
        });
        Schema::create('network_representatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('name', 160)->default('');
            $table->string('phone', 40)->default('');
            $table->string('address', 500)->default('');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['agent_account_id', 'status']);
        });
        Schema::create('representative_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('representative_id')->constrained('network_representatives')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('mime_type', 32);
            $table->unsignedInteger('bytes');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('pos_reference_profiles', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('pos_type_id')->nullable()->constrained('pos_types')->restrictOnDelete();
        });
        Schema::create('pos_representatives', function (Blueprint $table): void {
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('representative_id')->constrained('network_representatives')->restrictOnDelete();
            $table->primary(['account_id', 'representative_id']);
        });
    }

    public function down(): void
    {
        foreach (['pos_representatives', 'pos_reference_profiles', 'representative_photos', 'network_representatives', 'order_sources', 'pos_types'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('account_cities', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
