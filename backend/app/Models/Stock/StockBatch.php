<?php

namespace App\Models\Stock;

use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\OrderSource;
use Database\Factories\Stock\StockBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'quantity' => 'integer', 'rejected' => 'integer', 'cost_minor' => 'integer', 'expenses_minor' => 'integer', 'cost_total_minor' => 'integer', 'load_price_minor' => 'integer', 'amount_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(OrderSource::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StockCard::class, 'batch_id');
    }
}
