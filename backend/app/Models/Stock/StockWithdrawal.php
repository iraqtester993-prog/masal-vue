<?php

namespace App\Models\Stock;

use Database\Factories\Stock\StockWithdrawalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWithdrawal extends Model
{
    /** @use HasFactory<StockWithdrawalFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['card_ids' => 'array', 'original' => 'array', 'debit_minor' => 'integer', 'version' => 'integer', 'valid_until' => 'datetime', 'downloaded_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
