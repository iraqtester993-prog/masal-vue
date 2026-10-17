<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'digital_recent_order');
            $table->index(['provider', 'id'], 'digital_provider_recent');
            $table->index(['provider', 'status', 'id'], 'digital_provider_status_recent');
            $table->index(['provider', 'account_id', 'status', 'cost_basis', 'actual_cost_minor', 'actual_retail_minor', 'reservation_active', 'created_at'], 'digital_summary_cover');
        });
    }

    public function down(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->dropIndex('digital_recent_order');
            $table->dropIndex('digital_provider_recent');
            $table->dropIndex('digital_provider_status_recent');
            $table->dropIndex('digital_summary_cover');
        });
    }
};
