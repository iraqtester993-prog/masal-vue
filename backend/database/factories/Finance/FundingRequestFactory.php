<?php

namespace Database\Factories\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\FundingRequest;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FundingRequest> */
class FundingRequestFactory extends Factory
{
    protected $model = FundingRequest::class;

    public function definition(): array
    {
        return ['from_account_id' => fn () => app(AccountHierarchy::class)->create(fake()->company(), AccountType::System)->id, 'to_account_id' => fn (array $a) => app(AccountHierarchy::class)->create(fake()->company(), AccountType::MainAgent, Account::findOrFail($a['from_account_id']))->id, 'creator_id' => User::factory(), 'service' => 'cash', 'currency' => 'IQD', 'amount_minor' => 10000, 'purpose' => 'Test request', 'status' => 'pending', 'version' => 1];
    }
}
