<?php

namespace App\Http\Resources;

use App\Models\NetworkRepresentative;
use App\Models\OperatingGovernorate;
use App\Models\OrderSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = ['id' => $this->id, 'name' => $this->name, 'status' => $this->resource instanceof OperatingGovernorate ? ($this->active ? 'active' : 'disabled') : $this->status, 'version' => (int) $this->version];
        if ($this->resource instanceof OperatingGovernorate) {
            return $result + ['active' => $this->active, 'main_agents_count' => (int) $this->main_agents_count, 'sub_agents_count' => (int) $this->sub_agents_count, 'pos_count' => (int) $this->pos_count];
        }
        if ($this->resource instanceof OrderSource) {
            return $result + ['provider_id' => (int) $this->provider_id, 'provider_name' => $this->provider?->name, 'network_account_id' => (int) $this->network_account_id, 'network_name' => $this->network?->name];
        }
        if ($this->resource instanceof NetworkRepresentative) {
            return $result + ['phone' => $this->phone, 'address' => $this->address, 'agent_account_id' => (int) $this->agent_account_id, 'agent_name' => $this->agent?->name, 'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo): array => ['id' => $photo->id, 'url' => '/api/v1/reference/representatives/'.$this->id.'/photos/'.$photo->id.'/content', 'bytes' => $photo->bytes, 'mime_type' => $photo->mime_type])->all())];
        }

        return $result;
    }
}
