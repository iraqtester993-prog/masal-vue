<?php

namespace App\Models\Digital;

use Illuminate\Database\Eloquent\Model;

class TopupCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['use_name' => 'boolean', 'active' => 'boolean', 'cost_minor' => 'integer', 'retail_minor' => 'integer', 'version' => 'integer'];
    }
}
