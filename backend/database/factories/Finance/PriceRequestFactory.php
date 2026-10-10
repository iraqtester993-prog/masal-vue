<?php

namespace Database\Factories\Finance;

use App\Enums\AccountType;
use App\Models\Finance\PriceRequest;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceRequest> */
class PriceRequestFactory extends Factory
{
    protected $model = PriceRequest::class;

    public function definition(): array
    {
        return ['account_id' => fn () => app(AccountHierarchy::class)->create(fake()->company(), AccountType::MainAgent, app(AccountHierarchy::class)->create(fake()->company(), AccountType::System))->id, 'creator_id' => User::factory(), 'changes' => [], 'status' => 'pending', 'version' => 1];
    }
}
