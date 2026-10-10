<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('topup_categories', function (Blueprint $table): void {
            $table->dropUnique(['type', 'remote_id']);
            $table->foreignId('connection_id')->nullable()->constrained('digital_connections')->restrictOnDelete();
            $table->foreignId('catalog_snapshot_id')->nullable()->constrained('digital_catalog_snapshots')->restrictOnDelete();
            $table->unique(['connection_id', 'type', 'remote_id'], 'topup_category_connection_remote');
        });
        Schema::table('digital_offers', function (Blueprint $table): void {
            $table->foreignId('topup_category_id')->nullable()->unique()->constrained('topup_categories')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digital_offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('topup_category_id');
        });
        Schema::table('topup_categories', function (Blueprint $table): void {
            $table->dropUnique('topup_category_connection_remote');
            $table->dropConstrainedForeignId('catalog_snapshot_id');
            $table->dropConstrainedForeignId('connection_id');
        });
    }
};
