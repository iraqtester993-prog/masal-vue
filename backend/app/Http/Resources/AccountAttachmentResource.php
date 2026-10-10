<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'document_type' => $this->document_type,
            'label' => $this->resource->label(),
            'bytes' => $this->bytes,
            'mime_type' => $this->mime_type,
            'content_url' => route('account.attachments.content', ['id' => $this->account_id, 'attachment' => $this->id], absolute: false),
        ];
    }
}
