<?php

namespace Database\Factories\Operations;

use App\Models\Account;
use App\Models\Operations\OperationStop;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperationStop> */
class OperationStopFactory extends Factory
{
    protected $model = OperationStop::class;

    public function definition(): array
    {
        return ['creator_id' => User::factory(), 'authority_account_id' => fn () => Account::where('type', 'system')->firstOrFail()->id, 'scope' => 'pos', 'actions' => ['sales'], 'scope_roots' => null, 'include_descendants' => true, 'reason' => fake()->sentence(), 'active' => true, 'version' => 1];
    }
}
