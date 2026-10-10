<?php

namespace Database\Factories\Sales;

use App\Models\Sales\PrintRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintRule>
 */
class PrintRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3), 'targets' => [], 'policy' => ['failed_retries' => 2, 'max_cards' => 10, 'interval_seconds' => 5, 'daily_cards' => 0, 'daily_mode' => 'account', 'daily_product_mode' => 'all', 'daily_products' => []], 'active' => false, 'version' => 1,
        ];
    }
}
