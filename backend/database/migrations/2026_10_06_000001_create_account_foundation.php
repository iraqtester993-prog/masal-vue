<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('login', 190)->nullable()->unique();
            $table->enum('status', ['active', 'disabled'])->default('active');
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 190);
            $table->enum('type', ['system', 'main_agent', 'sub_agent', 'sub_branch', 'pos'])->index();
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('account_closure', function (Blueprint $table) {
            $table->foreignId('ancestor_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('accounts')->cascadeOnDelete();
            $table->unsignedInteger('depth');
            $table->primary(['ancestor_id', 'descendant_id']);
            $table->index(['descendant_id', 'depth']);
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
        });
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });
        Schema::create('account_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
        });
        Schema::create('membership_permissions', function (Blueprint $table) {
            $table->foreignId('membership_id')->constrained('account_memberships')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed');
            $table->primary(['membership_id', 'permission_id']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('portal', 20);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'membership_permissions', 'account_memberships', 'role_permissions', 'permissions', 'roles', 'account_closure', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login', 'status']);
        });
    }
};
