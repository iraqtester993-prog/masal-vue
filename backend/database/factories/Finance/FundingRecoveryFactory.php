<?php

namespace Database\Factories\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\FundingRecovery;
use App\Models\Finance\LedgerTransaction;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FundingRecovery> */
class FundingRecoveryFactory extends Factory
{
    protected $model = FundingRecovery::class;

    public function definition(): array
    {
        return ['transfer_id' => LedgerTransaction::factory()->state(['kind' => 'funding']), 'transaction_id' => LedgerTransaction::factory()->state(['kind' => 'recovery']), 'from_account_id' => fn () => app(AccountHierarchy::class)->create(fake()->company(), AccountType::MainAgent, app(AccountHierarchy::class)->create(fake()->company(), AccountType::System))->id, 'to_account_id' => fn (array $attributes) => app(AccountHierarchy::class)->create(fake()->company(), AccountType::Pos, Account::findOrFail($attributes['from_account_id']))->id, 'actor_id' => User::factory(), 'amount_minor' => 100, 'reason' => 'Test recovery', 'created_at' => now()];
    }
}
