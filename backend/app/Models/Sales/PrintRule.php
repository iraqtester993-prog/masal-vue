<?php

namespace App\Models\Sales;

use Database\Factories\Sales\PrintRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrintRule extends Model
{
    /** @use HasFactory<PrintRuleFactory> */
    use HasFactory;

    protected $table = 'sales_print_rules';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'targets' => 'array', 'policy' => 'array', 'active' => 'boolean'];
    }
}
