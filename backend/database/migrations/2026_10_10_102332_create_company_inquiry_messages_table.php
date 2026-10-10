<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_inquiry_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('company_inquiries')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('sender', 16);
            $table->text('body');
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['inquiry_id', 'idempotency_key']);
            $table->index(['inquiry_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_inquiry_messages');
    }
};
