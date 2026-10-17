<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_change_required_at')->nullable();
            $table->unsignedInteger('password_reset_version')->default(0);
        });
        Schema::create('managed_password_reset_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->cascadeOnDelete();
            $table->string('portal', 16);
            $table->char('nonce_hash', 64);
            $table->unsignedInteger('session_version')->nullable();
            $table->boolean('trusted')->default(false);
            $table->boolean('awaiting_device_proof')->default(false);
            $table->string('device_label', 120);
            $table->unsignedBigInteger('registered_device_id')->nullable();
            $table->unsignedInteger('binding_version')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('reset_version')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->index(['account_id', 'consumed_at', 'expires_at'], 'managed_reset_account_pending');
            $table->index(['expires_at', 'id'], 'managed_reset_expired');
        });
        if (app()->bound('masal.fresh_restore_database') && app('masal.fresh_restore_database') === DB::connection()) {
            return;
        }
        DB::table('permissions')->insertOrIgnore(['name' => 'account.password_reset']);
        $permission = DB::table('permissions')->where('name', 'account.password_reset')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['admin', 'agent'])->pluck('id') as $role) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_password_reset_requests');
        $ids = DB::table('permissions')->where('name', 'account.password_reset')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['password_change_required_at', 'password_reset_version']);
        });
    }
};
