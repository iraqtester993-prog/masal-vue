<?php

namespace App\Models\Stock;

use App\Models\Account;
use App\Models\CatalogProvider;
use App\Models\OrderSource;
use Database\Factories\Stock\StockOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOrder extends Model
{
    /** @use HasFactory<StockOrderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'preview_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'summary' => 'array', 'excluded_confirmed' => 'boolean', 'version' => 'integer', 'quantity' => 'integer', 'rejected' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CatalogProvider::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderSource::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'order_id');
    }
}
