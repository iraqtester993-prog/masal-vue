<?php

namespace Database\Factories;

use App\Models\CatalogProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogProduct>
 */
class CatalogProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Category '.fake()->unique()->numerify('######'), 'provider_id' => CatalogProviderFactory::new(),
            'kind' => 'محلية', 'face_value' => '5000.00', 'currency' => 'IQD', 'minimum_price' => '4500.00',
            'daily_limit_type' => 'quantity', 'daily_quantity' => 100, 'daily_amount' => null,
            'field_policy' => ['pin' => 'required', 'expiry' => 'required', 'serial' => 'unused', 'cvc' => 'unused', 'reference' => 'unused'],
            'extra_fields' => [], 'import_codes' => [], 'display_order' => 1, 'receipt_language' => '', 'receipt_width' => 80,
            'receipt_header' => '', 'receipt_footer' => '', 'allowed_cities' => [], 'status' => 'active', 'version' => 1,
        ];
    }
}
