<?php

namespace Database\Factories;

use App\Models\CatalogProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogProvider>
 */
class CatalogProviderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Company '.fake()->unique()->numerify('######'), 'supplier' => 'Recorded supplier', 'connection' => 'ملفات', 'status' => 'active', 'version' => 1,
        ];
    }
}
