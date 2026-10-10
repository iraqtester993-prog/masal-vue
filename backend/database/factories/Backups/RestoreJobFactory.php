<?php

namespace Database\Factories\Backups;

use App\Models\Backups\BackupPreview;
use App\Models\Backups\RestoreJob;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RestoreJob> */
class RestoreJobFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'creator_id' => User::factory(), 'preview_id' => BackupPreview::factory(), 'status' => 'queued', 'reviewed_at' => now()];
    }
}
