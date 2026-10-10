<?php

namespace App\Models;

use Database\Factories\CatalogProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogProvider extends Model
{
    /** @use HasFactory<CatalogProviderFactory> */
    use HasFactory;

    protected $fillable = ['name', 'supplier', 'connection', 'status', 'image_path', 'image_mime', 'version'];

    protected $attributes = ['version' => 1, 'status' => 'active'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
