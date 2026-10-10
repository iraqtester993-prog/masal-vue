<?php

namespace App\Models\Sales;

use Database\Factories\Sales\ReceiptLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceiptLayout extends Model
{
    /** @use HasFactory<ReceiptLayoutFactory> */
    use HasFactory;

    protected $table = 'sales_receipt_layouts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'layout' => 'array'];
    }
}
