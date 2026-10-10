<?php

namespace App\Models\Sales;

use Database\Factories\Sales\PrintPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintPolicy extends Model
{
    /** @use HasFactory<PrintPolicyFactory> */
    use HasFactory;

    protected $table = 'sales_policy';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'settings' => 'array', 'policy' => 'array'];
    }
}
