<?php

namespace Database\Factories;

use App\Models\OperatingGovernorate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperatingGovernorate> */
class OperatingGovernorateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Governorate '.fake()->unique()->numerify('######'), 'active' => true, 'version' => 1];
    }
}
