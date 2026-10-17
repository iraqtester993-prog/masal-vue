<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->indexes() as $table => [$name, $columns]) {
            if (! Schema::hasIndex($table, $name)) {
                if (DB::getDriverName() === 'mysql') {
                    DB::statement('ALTER TABLE '.$table.' ADD INDEX '.$name.' ('.implode(',', $columns).'), ALGORITHM=INPLACE, LOCK=NONE');
                } else {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->indexes(), true) as $table => [$name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    private function indexes(): array
    {
        return ['stock_cards' => ['stock_credit_mismatch', ['account_id', 'product_id', 'status', 'credit_held', 'credit_minor']],
            'digital_orders' => ['digital_connection_reservation_cost', ['connection_id', 'reservation_active', 'quoted_cost_minor']]];
    }
};
