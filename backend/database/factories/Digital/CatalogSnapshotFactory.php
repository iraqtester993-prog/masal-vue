<?php

namespace Database\Factories\Digital;

use App\Models\Digital\CatalogSnapshot;
use App\Models\Digital\DigitalConnection;
use App\Services\Digital\DigitalConfiguration;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogSnapshot>
 */
class CatalogSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => DigitalConnectionFactory::new(), 'actor_id' => UserFactory::new(), 'credential_hash' => fn (array $data): string => DigitalConfiguration::credentialHash(DigitalConnection::findOrFail($data['connection_id'])->credential), 'catalog' => [['key' => hash('sha256', '71:'), 'remote_id' => '71', 'remote_name' => 'Test catalogue category', 'province_id' => '', 'province' => '', 'package_type' => 'standard', 'type' => 'voucher', 'cost' => '4300.00', 'retail' => '5000.00']], 'bein_provinces' => [], 'created_at' => now(),
        ];
    }
}
