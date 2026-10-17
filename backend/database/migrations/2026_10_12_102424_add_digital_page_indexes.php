<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->index(['id', 'account_id'], 'digital_page_ids');
            $table->index(['provider', 'id', 'account_id'], 'digital_provider_page_ids');
            $table->index(['provider', 'status', 'id', 'account_id'], 'digital_provider_status_page_ids');
            $table->index(['account_id', 'id'], 'digital_account_page_ids');
        });
    }

    public function down(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->dropIndex('digital_page_ids');
            $table->dropIndex('digital_provider_page_ids');
            $table->dropIndex('digital_provider_status_page_ids');
            $table->dropIndex('digital_account_page_ids');
        });
    }
};
