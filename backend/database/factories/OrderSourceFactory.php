<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\OrderSource;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderSource> */
class OrderSourceFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Source '.fake()->unique()->numerify('######');

        return ['name' => $name, 'normalized_name' => mb_strtolower($name), 'provider_id' => CatalogProviderFactory::new(), 'network_account_id' => function (): int {
            $hierarchy = app(AccountHierarchy::class);
            $system = Account::where('type', AccountType::System)->first() ?? $hierarchy->create('Test System', AccountType::System);

            return $hierarchy->create('Test Network', AccountType::MainAgent, $system)->id;
        }, 'status' => 'active', 'version' => 1];
    }
}
