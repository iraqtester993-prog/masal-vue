<?php

namespace Database\Factories\Sales;

use App\Models\Sales\PrintPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrintPolicy>
 */
class PrintPolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 2, 'settings' => ['sales_enabled' => true, 'printing_enabled' => true, 'velocity_seconds' => 3, 'provider_daily_minor' => ['IQD' => 100000000, 'USD' => null], 'min_app_version' => '1.0.0', 'min_os_version' => null, 'reprint_limit' => 5], 'policy' => ['failed_retries' => 2, 'max_cards' => 10, 'interval_seconds' => 5, 'daily_cards' => 0, 'daily_mode' => 'account', 'daily_product_mode' => 'all', 'daily_products' => []], 'version' => 1,
        ];
    }
}
