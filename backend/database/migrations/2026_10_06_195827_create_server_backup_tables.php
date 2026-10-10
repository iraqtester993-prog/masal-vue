<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_write_gate', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('blocked')->default(false);
            $t->uuid('restore_job_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('updated_at')->nullable();
        });
        DB::table('runtime_write_gate')->insert(['id' => 1, 'blocked' => false, 'version' => 1]);
        Schema::create('server_backups', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20);
            $t->string('path')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->unsignedBigInteger('bytes')->default(0);
            $t->json('manifest')->nullable();
            $t->string('failure', 300)->nullable();
            $t->timestamps();
        });
        Schema::create('backup_previews', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('backup_id')->nullable()->constrained('server_backups')->nullOnDelete();
            $t->string('path');
            $t->char('sha256', 64);
            $t->json('manifest');
            $t->boolean('reference_verified')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        Schema::create('restore_jobs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUuid('preview_id')->constrained('backup_previews')->restrictOnDelete();
            $t->foreignUuid('safety_backup_id')->nullable()->constrained('server_backups')->nullOnDelete();
            $t->string('status', 24)->default('queued');
            $t->unsignedInteger('version')->default(1);
            $t->text('scratch')->nullable();
            $t->text('rollback')->nullable();
            $t->string('failure', 300)->nullable();
            $t->timestamp('reviewed_at');
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restore_jobs');
        Schema::dropIfExists('backup_previews');
        Schema::dropIfExists('server_backups');
        Schema::dropIfExists('runtime_write_gate');
    }
};
