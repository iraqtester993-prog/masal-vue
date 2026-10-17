<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['subject_account_id', 'created_at', 'id'], 'audit_subject_recent');
            $table->index(['account_id', 'created_at', 'id'], 'audit_account_recent');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_subject_recent');
            $table->dropIndex('audit_account_recent');
        });
    }
};
