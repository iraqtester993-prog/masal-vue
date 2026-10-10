<?php

namespace Database\Factories\Stock;

use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockClaim> */
class StockClaimFactory extends Factory
{
    protected $model = StockClaim::class;

    public function definition(): array
    {
        return ['batch_id' => StockBatchFactory::new(), 'account_id' => fn (array $attributes): int => StockBatch::findOrFail($attributes['batch_id'])->account_id, 'creator_id' => User::factory(), 'card_ids' => fn (array $attributes): array => [StockCardFactory::new()->create(['batch_id' => $attributes['batch_id'], 'status' => 'Quarantined', 'credit_held' => true])->id], 'reason' => 'Supplier investigation', 'quantity' => 1, 'credit_minor' => 450000, 'cost_minor' => 400000, 'status' => 'pending', 'version' => 1];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (StockClaim $claim): void {
            StockCard::where('batch_id', $claim->batch_id)->whereIn('id', $claim->card_ids)->update(['claim_id' => $claim->id]);
        });
    }
}
