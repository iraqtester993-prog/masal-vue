<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registered_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('identifier_hash', 64);
            $table->string('kind', 20);
            $table->string('model', 190)->nullable();
            $table->string('manufacturer', 100)->nullable();
            $table->string('serial', 190)->nullable();
            $table->string('os_version', 30)->nullable();
            $table->string('app_version', 30);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['account_id', 'identifier_hash']);
            $table->index(['account_id', 'last_seen_at']);
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->boolean('device_binding_enabled')->default(false);
        });
        Schema::table('sales_device_sessions', function (Blueprint $table): void {
            $table->foreignId('registered_device_id')->nullable()->constrained('pos_registered_devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_device_sessions', fn (Blueprint $table) => $table->dropConstrainedForeignId('registered_device_id'));
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('device_binding_enabled'));
        Schema::dropIfExists('pos_registered_devices');
    }
};
