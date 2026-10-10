<?php

namespace Database\Factories\Sales;

use App\Models\Account;
use App\Models\Sales\SaleLimit;
use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleLimit>
 */
class SaleLimitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => fn (): int => SaleFactory::new()->create()->account_id, 'product_id' => CatalogProductFactory::new(), 'authority_account_id' => fn (array $a): int => Account::findOrFail($a['account_id'])->parent_id, 'max_cards' => null, 'daily_quantity' => 10, 'daily_amount_minor' => null, 'version' => 1,
        ];
    }
}
