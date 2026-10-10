<?php

namespace Database\Factories\Operations;

use App\Models\Operations\AccountTimePolicy;
use App\Models\User;
use App\Services\Operations\AccountTimeGuard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountTimePolicy> */
class AccountTimePolicyFactory extends Factory
{
    protected $model = AccountTimePolicy::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'policy' => AccountTimeGuard::emptyPolicy(), 'version' => 1];
    }
}
