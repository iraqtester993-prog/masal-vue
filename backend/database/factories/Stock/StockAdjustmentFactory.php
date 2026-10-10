<?php

namespace Database\Factories\Stock;

use App\Models\Stock\StockAdjustment;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockAdjustment> */
class StockAdjustmentFactory extends Factory
{
    protected $model = StockAdjustment::class;

    public function definition(): array
    {
        return ['batch_id' => StockBatchFactory::new(), 'account_id' => fn (array $attributes): int => StockBatch::findOrFail($attributes['batch_id'])->account_id, 'creator_id' => User::factory(), 'kind' => 'cancellation', 'card_ids' => fn (array $attributes): array => [StockCardFactory::new()->create(['batch_id' => $attributes['batch_id'], 'status' => 'Cancelled by Reversal', 'credit_held' => true])->id], 'reason' => 'Supplier cancellation', 'quantity' => 1, 'credit_minor' => 450000, 'cost_minor' => 400000, 'invoice_amount_minor' => 450000, 'status' => 'cancelled', 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (StockAdjustment $adjustment): void {
            StockCard::where('batch_id', $adjustment->batch_id)->whereIn('id', $adjustment->card_ids)->update(['adjustment_id' => $adjustment->id]);
        });
    }
}
