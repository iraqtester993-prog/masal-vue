<?php

namespace Database\Factories\Stock;

use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use App\Models\Stock\StockWithdrawal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StockWithdrawal> */
class StockWithdrawalFactory extends Factory
{
    protected $model = StockWithdrawal::class;

    public function definition(): array
    {
        return ['batch_id' => StockBatchFactory::new(), 'account_id' => fn (array $attributes): int => StockBatch::findOrFail($attributes['batch_id'])->account_id, 'creator_id' => User::factory(), 'claim_id' => fn (array $attributes): int => StockClaimFactory::new()->create(['batch_id' => $attributes['batch_id'], 'creator_id' => $attributes['creator_id']])->id, 'card_ids' => fn (array $attributes): array => StockClaim::findOrFail($attributes['claim_id'])->card_ids, 'original' => fn (array $attributes): array => StockCard::whereIn('id', $attributes['card_ids'])->get()->map(fn ($card): array => ['id' => $card->id, 'status' => 'Quarantined', 'credit_held' => true, 'claim_id' => null])->all(), 'debit_minor' => 0, 'reason' => 'Supplier return', 'status' => 'pending', 'version' => 1];
    }
}
