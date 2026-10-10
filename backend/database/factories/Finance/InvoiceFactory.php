<?php

namespace Database\Factories\Finance;

use App\Enums\AccountType;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return ['account_id' => fn () => app(AccountHierarchy::class)->create(fake()->company(), AccountType::System)->id, 'creator_id' => User::factory(), 'kind' => 'payable', 'supplier' => fake()->company(), 'service' => 'cash', 'currency' => 'IQD', 'amount_minor' => 10000, 'paid_minor' => 0, 'reference' => fake()->uuid(), 'status' => 'unpaid', 'version' => 1];
    }
}
