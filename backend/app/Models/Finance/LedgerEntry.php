<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerEntry extends Model
{
    use HasFactory;

    protected $table = 'finance_entries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'amount_minor' => 'integer', 'balance_after_minor' => 'integer'];
    }
}
