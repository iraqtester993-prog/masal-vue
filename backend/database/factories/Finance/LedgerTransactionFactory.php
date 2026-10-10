<?php

namespace Database\Factories\Finance;

use App\Models\Finance\LedgerTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerTransaction> */
class LedgerTransactionFactory extends Factory
{
    protected $model = LedgerTransaction::class;

    public function definition(): array
    {
        return ['actor_id' => User::factory(), 'kind' => 'test-draft', 'service' => 'cash', 'currency' => 'IQD', 'reference' => fake()->uuid(), 'idempotency_key' => fake()->uuid(), 'payload_hash' => hash('sha256', fake()->uuid()), 'posted' => false, 'created_at' => now()];
    }
}
