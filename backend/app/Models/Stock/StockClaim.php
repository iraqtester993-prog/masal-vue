<?php

namespace App\Models\Stock;

use Database\Factories\Stock\StockClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockClaim extends Model
{
    /** @use HasFactory<StockClaimFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['card_ids' => 'array', 'version' => 'integer', 'quantity' => 'integer', 'credit_minor' => 'integer', 'cost_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
