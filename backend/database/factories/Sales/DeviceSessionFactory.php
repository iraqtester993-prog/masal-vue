<?php

namespace Database\Factories\Sales;

use App\Models\Sales\DeviceSession;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSession>
 */
class DeviceSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (): int => SaleFactory::new()->create()->account_id, 'user_id' => UserFactory::new(), 'session_hash' => hash('sha256', fake()->uuid()), 'session_version' => 1, 'app_version' => '1.0.0', 'last_seen_at' => now(), 'expires_at' => now()->addSeconds(90),
        ];
    }
}
