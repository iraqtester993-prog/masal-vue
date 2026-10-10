<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Policy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Policy> */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return ['id' => fake()->unique()->numberBetween(2, 1000000), 'daily_limit' => 1, 'amounts_minor' => [5000000], 'recovery_hours' => 24, 'version' => 1];
    }
}
