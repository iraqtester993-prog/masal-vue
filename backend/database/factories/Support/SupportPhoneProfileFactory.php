<?php

namespace Database\Factories\Support;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Support\SupportPhoneProfile;
use App\Services\AccountHierarchy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportPhoneProfile> */
class SupportPhoneProfileFactory extends Factory
{
    protected $model = SupportPhoneProfile::class;

    public function definition(): array
    {
        return ['account_id' => function (): int {
            $h = app(AccountHierarchy::class);
            $system = Account::where('type', AccountType::System)->first() ?? $h->create('Factory System', AccountType::System);

            return $h->create(fake()->company(), AccountType::MainAgent, $system)->id;
        }, 'phones' => [['label' => 'الدعم الفني', 'number' => '077'.fake()->numerify('########')]], 'version' => 1];
    }
}
