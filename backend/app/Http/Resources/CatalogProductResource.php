<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->only(['id', 'name', 'provider_id', 'kind', 'face_value', 'currency', 'minimum_price', 'daily_limit_type', 'daily_quantity', 'daily_amount', 'field_policy', 'extra_fields', 'display_order', 'import_codes', 'receipt_language', 'receipt_width', 'receipt_header', 'receipt_footer', 'allowed_cities', 'status', 'version', 'created_at']) + [
            'provider_name' => $this->provider->name, 'provider_status' => $this->provider->status,
            'provider_logo_url' => $this->provider->image_path ? '/api/v1/catalog/providers/'.$this->provider_id.'/image?v='.$this->provider->version : null,
            'image_url' => $this->image_path ? '/api/v1/catalog/products/'.$this->id.'/image?v='.$this->version : null,
        ];
    }
}
