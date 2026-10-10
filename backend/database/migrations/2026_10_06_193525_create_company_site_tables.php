<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->json('content');
            $table->json('published')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('editor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('company_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('path');
            $table->string('mime', 32);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamp('created_at');
        });
        Schema::create('company_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('contact', 150);
            $table->text('message');
            $table->string('status', 16)->default('new');
            $table->uuid('idempotency_key')->unique();
            $table->string('payload_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
        DB::table('company_profiles')->insert(['id' => 1, 'content' => json_encode(['name' => 'ماسال', 'tagline' => 'البطاقات والخدمات الإلكترونية', 'about' => '', 'phone' => '', 'email' => '', 'address' => '', 'website' => '', 'whatsapp' => '', 'hours' => '', 'logo' => null, 'slides' => [], 'activities' => [], 'offers' => [], 'projects' => [], 'social' => [], 'visibility' => array_fill_keys(['slides', 'about', 'activities', 'offers', 'projects', 'social', 'care'], true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_inquiries');
        Schema::dropIfExists('company_assets');
        Schema::dropIfExists('company_profiles');
    }
};
