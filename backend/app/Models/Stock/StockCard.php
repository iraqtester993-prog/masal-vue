<?php

namespace App\Models\Stock;

use Database\Factories\Stock\StockCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCard extends Model
{
    /** @use HasFactory<StockCardFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'pin_hash', 'serial_hash'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted:array', 'expiry' => 'date:Y-m-d', 'cost_minor' => 'integer', 'credit_minor' => 'integer', 'credit_held' => 'boolean', 'version' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
