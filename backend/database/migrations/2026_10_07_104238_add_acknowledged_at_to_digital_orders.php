<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            if (DB::getDriverName() === 'mysql') {
                $table->mediumText('provider_purchase_response')->nullable()->change();
            }
            $table->timestamp('acknowledged_at')->nullable();
            $table->index(['account_id', 'acknowledged_at']);
        });
        DB::table('digital_orders')->where('reservation_active', false)->update(['acknowledged_at' => DB::raw('resolved_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->dropIndex(['account_id', 'acknowledged_at']);
            $table->dropColumn('acknowledged_at');
        });
    }
};
