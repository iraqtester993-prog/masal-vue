<?php

namespace Database\Factories\Stock;

use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Services\Stock\StockImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockCard> */
class StockCardFactory extends Factory
{
    protected $model = StockCard::class;

    public function definition(): array
    {
        $serial = fake()->uuid();
        $pin = fake()->uuid();

        return ['batch_id' => StockBatchFactory::new(), 'account_id' => fn (array $attributes): int => StockBatch::findOrFail($attributes['batch_id'])->account_id, 'product_id' => fn (array $attributes): int => StockBatch::findOrFail($attributes['batch_id'])->product_id, 'serial' => $serial, 'serial_hash' => StockImport::fingerprint($serial), 'pin_hash' => StockImport::fingerprint($pin), 'secret' => ['pin' => $pin, 'cvc' => '', 'reference' => '', 'extra_fields' => []], 'expiry' => now()->addYear()->toDateString(), 'cost_minor' => 400000, 'credit_minor' => 450000, 'credit_held' => false, 'status' => 'Available', 'version' => 1];
    }
}
