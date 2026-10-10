<?php

namespace App\Models\Backups;

use Database\Factories\Backups\BackupPreviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BackupPreview extends Model
{
    /** @use HasFactory<BackupPreviewFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'reference_verified' => 'boolean', 'version' => 'integer', 'expires_at' => 'datetime'];
    }
}
