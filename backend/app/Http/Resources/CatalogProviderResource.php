<?php

namespace App\Http\Resources;

use App\Services\Finance\FinanceServiceIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_replace($this->only(['id', 'name', 'supplier', 'connection', 'status', 'version', 'created_at']), ['name' => app(FinanceServiceIdentity::class)->providerName($this->resource)]) + ['logo_url' => $this->image_path ? '/api/v1/catalog/providers/'.$this->id.'/image?v='.$this->version : null];
    }
}
