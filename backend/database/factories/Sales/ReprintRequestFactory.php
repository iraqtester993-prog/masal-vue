<?php

namespace Database\Factories\Sales;

use App\Models\Account;
use App\Models\Sales\ReprintRequest;
use App\Models\Sales\Sale;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReprintRequest>
 */
class ReprintRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => SaleFactory::new(), 'account_id' => fn (array $a): int => Sale::findOrFail($a['sale_id'])->account_id, 'creator_id' => UserFactory::new(), 'recipient_id' => fn (array $a): int => Account::findOrFail($a['account_id'])->parent_id, 'previous_status' => 'Print Failed', 'reason' => 'Printer paper jam', 'failure_reason' => 'Paper jam', 'status' => 'pending', 'history' => [], 'version' => 1,
        ];
    }
}
