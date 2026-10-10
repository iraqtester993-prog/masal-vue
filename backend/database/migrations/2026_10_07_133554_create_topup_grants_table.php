<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('main_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('target_account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $table->json('category_ids');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_grants');
    }
};
