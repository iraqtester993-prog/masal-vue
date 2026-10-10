<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_model', 'app_version'] as $field) {
                $table->string($field, in_array($field, ['support', 'address'], true) ? 500 : 190)->nullable();
            }
            $table->unique('serial');
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->unsignedInteger('session_version')->default(1);
        });
        Schema::create('account_cities', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('active')->default(true);
        });
        Schema::create('permission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('normalized_name', 80);
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['account_id', 'normalized_name']);
        });
        Schema::create('permission_profile_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['permission_profile_id', 'permission_id']);
        });
        Schema::table('account_memberships', function (Blueprint $table): void {
            $table->enum('kind', ['owner', 'employee'])->default('owner');
            $table->foreignId('permission_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('include_descendants')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('membership_scope_roots', function (Blueprint $table): void {
            $table->foreignId('membership_id')->constrained('account_memberships')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->primary(['membership_id', 'account_id']);
        });
        Schema::create('account_permission_rules', function (Blueprint $table): void {
            $table->foreignId('target_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('authority_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->boolean('allowed');
            $table->primary(['target_account_id', 'authority_account_id', 'permission_id'], 'account_permission_rules_pk');
        });
        Schema::table('audit_logs', fn (Blueprint $table) => $table->json('details')->nullable());
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::dropIfExists('account_permission_rules');
        Schema::dropIfExists('membership_scope_roots');
        Schema::table('account_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_profile_id');
            $table->dropColumn(['kind', 'include_descendants', 'notes', 'version']);
        });
        Schema::dropIfExists('permission_profile_permissions');
        Schema::dropIfExists('permission_profiles');
        Schema::dropIfExists('account_cities');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['serial']);
            $table->dropColumn(['city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_model', 'app_version', 'notes', 'version']);
        });
    }
};
