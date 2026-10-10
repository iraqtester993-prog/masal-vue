<?php

namespace App\Models;

use Database\Factories\CatalogProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogProduct extends Model
{
    /** @use HasFactory<CatalogProductFactory> */
    use HasFactory;

    protected $fillable = ['name', 'provider_id', 'kind', 'face_value', 'currency', 'minimum_price', 'daily_limit_type', 'daily_quantity', 'daily_amount', 'field_policy', 'extra_fields', 'display_order', 'import_codes', 'receipt_language', 'receipt_width', 'receipt_header', 'receipt_footer', 'allowed_cities', 'status', 'image_path', 'image_mime', 'version'];

    protected $attributes = ['version' => 1, 'status' => 'active'];

    protected function casts(): array
    {
        return ['face_value' => 'decimal:2', 'minimum_price' => 'decimal:2', 'daily_amount' => 'decimal:2', 'field_policy' => 'array', 'extra_fields' => 'array', 'import_codes' => 'array', 'allowed_cities' => 'array', 'version' => 'integer', 'display_order' => 'integer', 'daily_quantity' => 'integer', 'receipt_width' => 'integer'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(CatalogProvider::class);
    }
}
