<?php

namespace Database\Factories\Finance;

use App\Enums\AccountType;
use App\Models\Finance\Wallet;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Wallet> */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return ['account_id' => fn () => app(AccountHierarchy::class)->create(fake()->company(), AccountType::System)->id, 'service' => 'cash', 'currency' => 'IQD', 'kind' => 'account', 'balance_minor' => 0, 'held_minor' => 0, 'version' => 1];
    }
}
