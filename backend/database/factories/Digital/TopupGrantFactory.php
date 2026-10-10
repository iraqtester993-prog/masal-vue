<?php

namespace Database\Factories\Digital;

use App\Models\Digital\TopupGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TopupGrant> */
class TopupGrantFactory extends Factory
{
    protected $model = TopupGrant::class;

    public function definition(): array
    {
        return ['category_ids' => [], 'active' => true, 'version' => 1];
    }
}
