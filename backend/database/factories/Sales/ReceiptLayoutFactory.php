<?php

namespace Database\Factories\Sales;

use App\Models\Sales\ReceiptLayout;
use App\Services\Sales\ReceiptDesign;
use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceiptLayout>
 */
class ReceiptLayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => CatalogProductFactory::new(), 'account_id' => null, 'scope_key' => fn (array $a): string => 'system:'.$a['product_id'], 'layout' => ['header' => '', 'footer' => '', 'color' => '#172b4d', 'display_order' => ReceiptDesign::BLOCKS], 'version' => 1,
        ];
    }
}
