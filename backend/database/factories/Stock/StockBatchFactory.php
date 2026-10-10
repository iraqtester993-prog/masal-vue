<?php

namespace Database\Factories\Stock;

use App\Models\OrderSource;
use App\Models\Stock\StockBatch;
use Database\Factories\CatalogProductFactory;
use Database\Factories\OrderSourceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockBatch> */
class StockBatchFactory extends Factory
{
    protected $model = StockBatch::class;

    public function definition(): array
    {
        return ['source_id' => OrderSourceFactory::new(), 'account_id' => fn (array $attributes): int => OrderSource::findOrFail($attributes['source_id'])->network_account_id, 'product_id' => fn (array $attributes): int => CatalogProductFactory::new()->create(['provider_id' => OrderSource::findOrFail($attributes['source_id'])->provider_id])->id, 'line_key' => fake()->uuid(), 'currency' => 'IQD', 'file_name' => 'stock.csv', 'city' => 'بغداد', 'supplier' => 'Test supplier', 'quantity' => 1, 'rejected' => 0, 'cost_minor' => 400000, 'expenses_minor' => 0, 'cost_total_minor' => 400000, 'load_price_minor' => 450000, 'amount_minor' => 450000, 'status' => 'Loaded', 'version' => 1];
    }
}
