<?php

namespace Database\Factories\Support;

use App\Models\Support\SupportOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportOperation> */
class SupportOperationFactory extends Factory
{
    protected $model = SupportOperation::class;

    public function definition(): array
    {
        $key = fake()->uuid();

        return ['user_id' => User::factory(), 'key' => $key, 'action' => 'support.create', 'fingerprint' => hash('sha256', $key), 'result' => ['id' => 1]];
    }
}
