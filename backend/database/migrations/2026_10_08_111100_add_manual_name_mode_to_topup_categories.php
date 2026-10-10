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
        Schema::table('topup_categories', function (Blueprint $table): void {
            $table->boolean('use_name')->default(false);
        });
        Schema::table('digital_offers', function (Blueprint $table): void {
            $table->index('topup_category_id', 'digital_offers_topup_category_lookup');
            $table->dropUnique(['topup_category_id']);
            $table->unique(['connection_id', 'topup_category_id'], 'digital_offer_topup_category');
            $table->unsignedInteger('manual_category_version')->nullable();
        });
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->boolean('manual_category_name')->default(false);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER digital_orders_manual_source_immutable BEFORE UPDATE ON digital_orders WHEN NEW.manual_category_name IS NOT OLD.manual_category_name BEGIN SELECT RAISE(ABORT, 'Digital category source is immutable'); END");
        } elseif (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER digital_orders_manual_source_immutable BEFORE UPDATE ON digital_orders FOR EACH ROW BEGIN IF NOT (NEW.manual_category_name <=> OLD.manual_category_name) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Digital category source is immutable'; END IF; END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS digital_orders_manual_source_immutable');
        Schema::table('digital_orders', function (Blueprint $table): void {
            $table->dropColumn('manual_category_name');
        });
        Schema::table('digital_offers', function (Blueprint $table): void {
            $table->dropUnique('digital_offer_topup_category');
            $table->dropColumn('manual_category_version');
        });
        Schema::table('topup_categories', function (Blueprint $table): void {
            $table->dropColumn('use_name');
        });
    }
};
