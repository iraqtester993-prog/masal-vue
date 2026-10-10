<?php

namespace App\Models;

use Database\Factories\OrderSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSource extends Model
{
    /** @use HasFactory<OrderSourceFactory> */
    use HasFactory;

    protected $fillable = ['network_account_id', 'provider_id', 'name', 'normalized_name', 'status', 'version'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CatalogProvider::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'network_account_id');
    }
}
