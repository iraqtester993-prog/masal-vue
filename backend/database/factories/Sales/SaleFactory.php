<?php

namespace Database\Factories\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\Sales\Sale;
use App\Services\AccountHierarchy;
use Database\Factories\CatalogProductFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'main_account_id' => function (): int {
                $main = Account::where('type', 'main_agent')->first();
                if ($main) {
                    return $main->id;
                } $system = Account::where('type', 'system')->first() ?? app(AccountHierarchy::class)->create('Test system', AccountType::System);

                return app(AccountHierarchy::class)->create('Test main', AccountType::MainAgent, $system)->id;
            },
            'account_id' => fn (array $attributes): int => app(AccountHierarchy::class)->create('Test seller', AccountType::SubAgent, Account::findOrFail($attributes['main_account_id']))->id,
            'creator_id' => UserFactory::new(), 'product_id' => CatalogProductFactory::new(), 'provider_id' => fn (array $attributes): int => CatalogProduct::findOrFail($attributes['product_id'])->provider_id, 'currency' => 'IQD', 'quantity' => 1, 'price_minor' => 10000, 'total_minor' => 10000, 'retail_price_minor' => 11000, 'retail_total_minor' => 11000, 'credit_minor' => 10000, 'cost_minor' => 9000, 'load_cost_minor' => 10000, 'price_version' => 1, 'status' => 'Reserved', 'version' => 1,
        ];
    }
}
