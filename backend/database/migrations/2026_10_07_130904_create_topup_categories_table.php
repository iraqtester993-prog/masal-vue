<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('type', 16);
            $table->string('remote_id', 100)->nullable();
            $table->unique(['type', 'remote_id']);
            $table->unsignedBigInteger('cost_minor')->nullable();
            $table->unsignedBigInteger('retail_minor')->nullable();
            $table->boolean('active')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_categories');
    }
};
