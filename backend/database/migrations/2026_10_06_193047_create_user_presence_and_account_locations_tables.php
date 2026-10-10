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
        Schema::create('user_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('session_hash', 64)->unique();
            $table->unsignedInteger('session_version');
            $table->boolean('connected')->default(true);
            $table->boolean('sharing')->default(false);
            $table->unsignedInteger('consent_version')->default(1);
            $table->decimal('latitude', 11, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();
            $table->decimal('accuracy', 12, 2)->nullable();
            $table->timestamp('location_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->string('device_model', 120)->default('');
            $table->string('app_version', 60)->default('');
            $table->timestamps();
            $table->index(['user_id', 'last_seen_at']);
        });
        Schema::create('account_locations', function (Blueprint $table) {
            $table->foreignId('account_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('latitude', 11, 7);
            $table->decimal('longitude', 11, 7);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_locations');
        Schema::dropIfExists('user_presences');
    }
};
