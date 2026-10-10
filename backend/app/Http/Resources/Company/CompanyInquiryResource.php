<?php

namespace App\Http\Resources\Company;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyInquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'contact' => $this->contact, 'message' => $this->message, 'status' => $this->status, 'version' => $this->version, 'time' => $this->created_at->toISOString(), 'reviewed_at' => $this->reviewed_at?->toISOString()];
    }
}
