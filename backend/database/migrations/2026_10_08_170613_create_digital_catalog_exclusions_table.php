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
        Schema::create('digital_catalog_exclusions', function (Blueprint $table) {
            $table->foreignId('snapshot_id')->primary()->constrained('digital_catalog_snapshots');
            $table->json('excluded_catalog');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_catalog_exclusions');
    }
};
