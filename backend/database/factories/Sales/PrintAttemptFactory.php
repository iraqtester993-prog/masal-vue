<?php

namespace Database\Factories\Sales;

use App\Models\Sales\PrintAttempt;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintAttempt>
 */
class PrintAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => SaleFactory::new(), 'actor_id' => UserFactory::new(), 'kind' => 'initial', 'status' => 'pending', 'started_at' => now(), 'version' => 1,
        ];
    }
}
