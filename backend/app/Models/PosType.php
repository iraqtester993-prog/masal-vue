<?php

namespace App\Models;

use Database\Factories\PosTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosType extends Model
{
    /** @use HasFactory<PosTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'status', 'version'];

    protected $attributes = ['status' => 'active', 'version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
