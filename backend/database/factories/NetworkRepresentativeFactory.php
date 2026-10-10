<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\NetworkRepresentative;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NetworkRepresentative> */
class NetworkRepresentativeFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->name(), 'phone' => fake()->numerify('077########'), 'address' => fake()->streetAddress(), 'agent_account_id' => function (): int {
            $hierarchy = app(AccountHierarchy::class);
            $system = Account::where('type', AccountType::System)->first() ?? $hierarchy->create('Test System', AccountType::System);

            return $hierarchy->create('Test Network', AccountType::MainAgent, $system)->id;
        }, 'status' => 'active', 'version' => 1];
    }
}
