<?php

namespace Database\Factories;

use App\Models\PermissionProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PermissionProfile> */
class PermissionProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'status' => 'active', 'version' => 1];
    }
}
