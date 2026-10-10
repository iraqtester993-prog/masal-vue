<?php

namespace Database\Factories\Digital;

use App\Models\Digital\ProviderAttempt;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderAttempt>
 */
class ProviderAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => DigitalOrderFactory::new(), 'actor_id' => UserFactory::new(), 'dispatch_key' => fake()->uuid(), 'payload_hash' => hash('sha256', fake()->uuid()), 'operation' => 'submit', 'status' => 'dispatching', 'started_at' => now(),
        ];
    }
}
