<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable()->index());
        Schema::create('operation_stops', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('authority_account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('scope', 20);
            $t->json('actions');
            $t->json('scope_roots')->nullable();
            $t->boolean('include_descendants')->default(true);
            $t->string('reason', 300);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('resumed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('resumed_at')->nullable();
            $t->timestamps();
            $t->index(['active', 'scope']);
        });
        foreach (['operation_stop_targets', 'operation_stop_scope_roots'] as $name) {
            Schema::create($name, function (Blueprint $t): void {
                $t->foreignId('stop_id')->constrained('operation_stops')->restrictOnDelete();
                $t->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
                $t->primary(['stop_id', 'account_id']);
                $t->index(['account_id', 'stop_id']);
            });
        }
        Schema::create('operation_direct_stops', function (Blueprint $t): void {
            $t->foreignId('account_id')->primary()->constrained('accounts')->restrictOnDelete();
            $t->json('stops');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('account_time_policies', function (Blueprint $t): void {
            $t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();
            $t->json('policy');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('operation_mutations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('key', 100);
            $t->string('action', 60);
            $t->string('fingerprint', 64);
            $t->json('result')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'key']);
        });
        Schema::create('account_archives', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 500);
            $t->longText('before');
            $t->longText('users');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['account_archives', 'operation_mutations', 'account_time_policies', 'operation_direct_stops', 'operation_stop_scope_roots', 'operation_stop_targets', 'operation_stops'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('accounts', fn (Blueprint $t) => $t->dropColumn('archived_at'));
    }
};
