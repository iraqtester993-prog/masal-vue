<?php

namespace Database\Factories\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Services\AccountHierarchy;
use Database\Factories\CatalogProviderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalConnection>
 */
class DigitalConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => function (): int {
                $hierarchy = app(AccountHierarchy::class);
                $system = Account::where('type', AccountType::System)->first() ?? $hierarchy->create('Test system', AccountType::System);

                return $hierarchy->create('Test connection owner', AccountType::MainAgent, $system)->id;
            },
            'provider_id' => CatalogProviderFactory::new()->state(['connection' => 'API']), 'provider' => 'rabiaa', 'credential' => fake()->regexify('[A-Za-z0-9]{32}'), 'active' => true, 'bein_provinces' => [], 'version' => 1,
        ];
    }
}
