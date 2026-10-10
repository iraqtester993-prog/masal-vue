<?php

namespace App\Models\Sales;

use Database\Factories\Sales\SaleLimitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleLimit extends Model
{
    /** @use HasFactory<SaleLimitFactory> */
    use HasFactory;

    protected $table = 'sales_limits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'max_cards' => 'integer', 'daily_quantity' => 'integer', 'daily_amount_minor' => 'integer'];
    }
}
