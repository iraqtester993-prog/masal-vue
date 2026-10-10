<?php

namespace Database\Factories\Stock;

use App\Models\OrderSource;
use App\Models\Stock\StockOrder;
use App\Models\User;
use App\Services\Stock\StockImport;
use Database\Factories\OrderSourceFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockOrder> */
class StockOrderFactory extends Factory
{
    protected $model = StockOrder::class;

    public function definition(): array
    {
        return ['source_id' => OrderSourceFactory::new(), 'account_id' => fn (array $attributes): int => OrderSource::findOrFail($attributes['source_id'])->network_account_id, 'provider_id' => fn (array $attributes): int => OrderSource::findOrFail($attributes['source_id'])->provider_id, 'creator_id' => User::factory(), 'city' => 'بغداد', 'status' => 'pending', 'preview_hash' => StockImport::fingerprint(fake()->uuid()), 'payload' => ['order_key' => fake()->uuid(), 'lines' => []], 'summary' => ['quantity' => 1, 'rejected' => 0, 'category_count' => 1, 'amounts' => [], 'lines' => [], 'product_ids' => []], 'quantity' => 1, 'rejected' => 0, 'version' => 1, 'excluded_confirmed' => false];
    }
}
