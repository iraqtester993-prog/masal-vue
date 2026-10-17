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
        if (Schema::hasIndex('stock_cards', 'stock_card_sale_lookup')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE stock_cards ADD INDEX stock_card_sale_lookup (sale_id, id), ALGORITHM=INPLACE, LOCK=NONE');
        } else {
            Schema::table('stock_cards', function (Blueprint $table): void {
                $table->index(['sale_id', 'id'], 'stock_card_sale_lookup');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasIndex('stock_cards', 'stock_card_sale_lookup')) {
            Schema::table('stock_cards', function (Blueprint $table): void {
                $table->dropIndex('stock_card_sale_lookup');
            });
        }
    }
};
