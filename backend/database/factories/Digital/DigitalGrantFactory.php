<?php

namespace Database\Factories\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalGrant;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalGrant>
 */
class DigitalGrantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => DigitalConnectionFactory::new(), 'from_account_id' => fn (array $data): int => DigitalConnection::findOrFail($data['connection_id'])->account_id, 'target_account_id' => fn (array $data): int => app(AccountHierarchy::class)->create('Test granted child', AccountType::Pos, Account::findOrFail($data['from_account_id']))->id, 'offer_ids' => [], 'active' => true, 'version' => 1,
        ];
    }
}
