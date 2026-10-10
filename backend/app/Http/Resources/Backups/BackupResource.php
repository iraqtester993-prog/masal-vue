<?php

namespace App\Http\Resources\Backups;

use App\Models\Backups\BackupPreview;
use App\Models\Backups\RestoreJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'created_at' => $this->created_at?->toIso8601String()];
        if ($this->resource instanceof RestoreJob) {
            return $data + ['status' => $this->status, 'version' => $this->version, 'failure' => $this->failure, 'safety_backup_id' => $this->safety_backup_id, 'completed_at' => $this->completed_at?->toIso8601String()];
        }
        if ($this->resource instanceof BackupPreview) {
            return $data + ['version' => $this->version, 'sha256' => $this->sha256, 'manifest' => $this->manifest, 'reference_verified' => $this->reference_verified, 'expires_at' => $this->expires_at->toIso8601String()];
        }

        return $data + ['status' => $this->status, 'bytes' => $this->bytes, 'sha256' => $this->sha256, 'manifest' => $this->manifest, 'failure' => $this->failure];
    }
}
