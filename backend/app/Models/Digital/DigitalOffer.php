<?php

namespace App\Models\Digital;

use App\Models\CatalogProduct;
use Database\Factories\Digital\DigitalOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalOffer extends Model
{
    /** @use HasFactory<DigitalOfferFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['manual_category_version' => 'integer', 'active' => 'boolean', 'listed' => 'boolean', 'version' => 'integer', 'cost_minor' => 'integer', 'retail_minor' => 'integer'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DigitalConnection::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
