<?php

namespace Database\Factories\Operations;

use App\Models\Account;
use App\Models\Operations\AccountArchive;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountArchive> */
class AccountArchiveFactory extends Factory
{
    protected $model = AccountArchive::class;

    public function definition(): array
    {
        return ['account_id' => fn () => Account::where('type', '<>', 'system')->firstOrFail()->id, 'actor_id' => User::factory(), 'reason' => fake()->sentence(), 'before' => ['name' => fake()->company(), 'type' => 'main_agent', 'status' => 'active'], 'users' => [], 'created_at' => now()];
    }
}
