<?php

namespace Database\Factories\Finance;

use App\Models\Finance\LedgerEntry;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LedgerEntry> */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        return ['transaction_id' => LedgerTransaction::factory(), 'wallet_id' => Wallet::factory(), 'amount_minor' => 100, 'balance_after_minor' => 100, 'created_at' => now()];
    }
}
