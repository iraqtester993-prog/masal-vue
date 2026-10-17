<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_registered_devices', function (Blueprint $table): void {
            $table->text('proof_public_key')->nullable();
            $table->string('proof_key_kind', 30)->nullable();
            $table->timestamp('proof_enrolled_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('sales_device_sessions', function (Blueprint $table): void {
            $table->string('proof_key_hash', 64)->nullable();
            $table->timestamp('proof_verified_at')->nullable();
        });
        DB::table('accounts')->where('type', 'pos')->update(['device_binding_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('sales_device_sessions', fn (Blueprint $table) => $table->dropColumn(['proof_key_hash', 'proof_verified_at']));
        Schema::table('pos_registered_devices', fn (Blueprint $table) => $table->dropColumn(['proof_public_key', 'proof_key_kind', 'proof_enrolled_at', 'archived_at']));
    }
};
