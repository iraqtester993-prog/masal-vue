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
        Schema::table('digital_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('provider_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('digital_connections')->whereNull('provider_id')->exists()) {
            throw new RuntimeException('لا يمكن إلغاء هذا الترحيل مع ربط شركة رقمية دون معرف شركة محلية.');
        }
        Schema::table('digital_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('provider_id')->nullable(false)->change();
        });
    }
};
