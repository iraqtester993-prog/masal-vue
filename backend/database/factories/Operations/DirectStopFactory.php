<?php

namespace Database\Factories\Operations;

use App\Models\Account;
use App\Models\Operations\DirectStop;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DirectStop> */
class DirectStopFactory extends Factory
{
    protected $model = DirectStop::class;

    public function definition(): array
    {
        return ['account_id' => fn () => Account::where('type', '<>', 'system')->firstOrFail()->id, 'stops' => ['sales' => true, 'login' => false, 'printing' => false, 'import' => false], 'version' => 1];
    }
}
