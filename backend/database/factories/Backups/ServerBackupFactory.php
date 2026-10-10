<?php

namespace Database\Factories\Backups;

use App\Models\Backups\ServerBackup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServerBackup> */
class ServerBackupFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'creator_id' => User::factory(), 'status' => 'creating'];
    }
}
