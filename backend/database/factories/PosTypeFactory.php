<?php

namespace Database\Factories;

use App\Models\PosType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PosType> */
class PosTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = 'POS type '.fake()->unique()->numerify('######');

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'status' => 'active', 'version' => 1];
    }
}
