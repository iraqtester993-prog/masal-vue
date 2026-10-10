<?php

namespace App\Models;

use Database\Factories\OperatingGovernorateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingGovernorate extends Model
{
    /** @use HasFactory<OperatingGovernorateFactory> */
    use HasFactory;

    protected $table = 'account_cities';

    public $timestamps = false;

    protected $fillable = ['name', 'active', 'version'];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
