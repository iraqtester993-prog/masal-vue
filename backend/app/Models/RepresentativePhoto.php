<?php

namespace App\Models;

use Database\Factories\RepresentativePhotoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepresentativePhoto extends Model
{
    /** @use HasFactory<RepresentativePhotoFactory> */
    use HasFactory;

    protected $fillable = ['representative_id', 'storage_path', 'mime_type', 'bytes', 'uploaded_by'];

    protected $hidden = ['storage_path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }
}
