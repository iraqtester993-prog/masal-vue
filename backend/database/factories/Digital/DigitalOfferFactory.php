<?php

namespace Database\Factories\Digital;

use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOffer;
use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DigitalOffer>
 */
class DigitalOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => DigitalConnectionFactory::new(), 'product_id' => fn (array $data): int => CatalogProductFactory::new()->create(['provider_id' => DigitalConnection::findOrFail($data['connection_id'])->provider_id])->id, 'catalog_snapshot_id' => fn (array $data): int => CatalogSnapshotFactory::new()->create(['connection_id' => $data['connection_id']])->id, 'remote_id' => '71', 'remote_key' => hash('sha256', '71:'), 'remote_name' => 'Test company category', 'type' => 'voucher', 'package_type' => 'standard', 'cost_minor' => 430000, 'retail_minor' => 500000, 'listed' => true, 'active' => true, 'version' => 1,
        ];
    }
}
