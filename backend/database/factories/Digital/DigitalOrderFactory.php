<?php

namespace Database\Factories\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use App\Models\Digital\DigitalOrder;
use App\Services\AccountHierarchy;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalOrder>
 */
class DigitalOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => DigitalOfferFactory::new(), 'connection_id' => fn (array $data): int => DigitalOffer::findOrFail($data['offer_id'])->connection_id, 'main_account_id' => fn (array $data): int => DigitalConnection::findOrFail($data['connection_id'])->account_id, 'account_id' => fn (array $data): int => app(AccountHierarchy::class)->create('Test service point', AccountType::Pos, Account::findOrFail($data['main_account_id']))->id, 'creator_id' => UserFactory::new(), 'product_id' => fn (array $data): int => DigitalOffer::findOrFail($data['offer_id'])->product_id, 'provider' => 'rabiaa', 'request_id' => fake()->uuid(), 'request_hash' => hash('sha256', fake()->uuid()), 'remote_id' => '71', 'type' => 'voucher', 'package_type' => 'standard', 'offer_version' => 1, 'quoted_cost_minor' => 430000, 'quoted_retail_minor' => 500000, 'status' => 'pending', 'reservation_active' => true, 'version' => 1,
        ];
    }
}
