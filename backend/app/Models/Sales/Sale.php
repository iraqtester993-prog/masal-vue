<?php

namespace App\Models\Sales;

use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\CatalogProvider;
use App\Models\Stock\StockCard;
use App\Models\User;
use Database\Factories\Sales\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $table = 'sales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'version' => 'integer', 'price_minor' => 'integer', 'total_minor' => 'integer', 'retail_price_minor' => 'integer', 'retail_total_minor' => 'integer', 'credit_minor' => 'integer', 'cost_minor' => 'integer', 'load_cost_minor' => 'integer', 'price_version' => 'integer', 'print_pending' => 'boolean', 'failure_retry' => 'boolean', 'reprints' => 'integer', 'failed_retry_count' => 'integer', 'issued_at' => 'datetime', 'exposed_at' => 'datetime', 'print_started_at' => 'datetime', 'first_printed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'name', 'type', 'parent_id', 'city', 'color']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'name']);
    }

    public function mainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'main_account_id')->select(['id', 'name']);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CatalogProvider::class, 'provider_id')->select(['id', 'name']);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(StockCard::class, 'sale_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PrintAttempt::class, 'sale_id')->orderBy('id');
    }
}
