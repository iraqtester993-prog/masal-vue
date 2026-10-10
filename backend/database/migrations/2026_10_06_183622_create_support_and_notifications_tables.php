<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('path')->unique();
            $table->string('mime', 30);
            $table->unsignedInteger('bytes');
            $table->timestamp('expires_at');
            $table->boolean('assigned')->default(false);
            $table->timestamps();
        });
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('origin_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('broadcast_recipient_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('broadcast_group')->nullable()->index();
            $table->foreignId('attachment_id')->nullable()->constrained('support_attachments')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->json('route_stack');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_message_at');
            $table->timestamps();
            $table->index(['recipient_id', 'status', 'id']);
        });
        Schema::create('support_ticket_participants', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->primary(['ticket_id', 'account_id']);
            $table->index(['account_id', 'ticket_id']);
        });
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->boolean('is_opening')->default(false);
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });
        Schema::create('support_message_audience', function (Blueprint $table): void {
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->primary(['message_id', 'account_id']);
        });
        Schema::create('support_reads', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('last_message_id')->default(0);
            $table->timestamp('read_at');
            $table->primary(['ticket_id', 'user_id']);
        });
        Schema::create('support_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 30);
            $table->foreignId('from_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('support_phone_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->unique()->constrained()->restrictOnDelete();
            $table->json('phones');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('support_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('key', 100);
            $table->string('action', 60);
            $table->char('fingerprint', 64);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
        Schema::create('notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('support_attachments')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('body');
            $table->json('translations')->nullable();
            $table->string('page', 50)->nullable();
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('event_key', 150)->nullable()->unique();
            $table->timestamps();
            $table->index(['page', 'entity_id']);
        });
        Schema::create('notice_recipients', function (Blueprint $table): void {
            $table->foreignId('notice_id')->constrained('notices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->primary(['notice_id', 'user_id']);
            $table->index(['user_id', 'read_at', 'notice_id']);
        });
    }

    public function down(): void
    {
        foreach (['notice_recipients', 'notices', 'support_operations', 'support_phone_profiles', 'support_history', 'support_reads', 'support_message_audience', 'support_messages', 'support_ticket_participants', 'support_tickets', 'support_attachments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
