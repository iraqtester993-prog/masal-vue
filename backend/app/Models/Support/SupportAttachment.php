<?php

namespace App\Models\Support;

use Database\Factories\Support\SupportAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportAttachment extends Model
{
    /** @use HasFactory<SupportAttachmentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['assigned' => 'boolean', 'expires_at' => 'datetime', 'bytes' => 'integer'];
    }
}
