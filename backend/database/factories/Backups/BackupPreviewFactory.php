<?php

namespace Database\Factories\Backups;

use App\Models\Backups\BackupPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BackupPreview> */
class BackupPreviewFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'creator_id' => User::factory(), 'path' => 'backups/previews/'.Str::uuid().'.json', 'sha256' => str_repeat('0', 64), 'manifest' => [], 'reference_verified' => false, 'expires_at' => now()->addHour()];
    }
}
