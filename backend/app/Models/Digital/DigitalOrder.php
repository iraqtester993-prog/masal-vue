<?php

namespace App\Models\Digital;

use App\Models\Account;
use App\Models\CatalogProduct;
use App\Models\User;
use Database\Factories\Digital\DigitalOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DigitalOrder extends Model
{
    /** @use HasFactory<DigitalOrderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['subscriber', 'receipt', 'receipt_ref', 'request_hash', 'provider_hold', 'provider_evidence', 'provider_purchase_response'];

    protected function casts(): array
    {
        return ['manual_category_name' => 'boolean', 'acknowledged_at' => 'datetime', 'subscriber' => 'encrypted:array', 'receipt' => 'encrypted:array', 'provider_hold' => 'encrypted:array', 'provider_evidence' => 'encrypted:array', 'provider_purchase_response' => 'encrypted:array', 'provider_purchase_started_at' => 'datetime', 'reservation_active' => 'boolean', 'version' => 'integer', 'offer_version' => 'integer', 'quoted_cost_minor' => 'integer', 'quoted_retail_minor' => 'integer', 'actual_cost_minor' => 'integer', 'actual_retail_minor' => 'integer', 'resolved_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DigitalConnection::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function mainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'main_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProviderAttempt::class, 'order_id');
    }
}
