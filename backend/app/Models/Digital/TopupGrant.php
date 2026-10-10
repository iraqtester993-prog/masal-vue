<?php

namespace App\Models\Digital;

use Database\Factories\Digital\TopupGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TopupGrant extends Model
{
    /** @use HasFactory<TopupGrantFactory> */
    use HasFactory;

    protected $guarded = ['id'];
    protected $attributes = ['balance_minor' => 0, 'held_minor' => 0, 'spent_minor' => 0];

    protected function casts(): array
    {
        return ['category_ids' => 'array', 'retail_prices' => 'array', 'balance_minor' => 'integer', 'held_minor' => 'integer', 'spent_minor' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }
}
