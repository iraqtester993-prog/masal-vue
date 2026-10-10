<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LedgerTransaction extends Model
{
    use HasFactory;

    protected $table = 'finance_transactions';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['posted' => 'boolean', 'created_at' => 'datetime', 'recovery_deadline' => 'datetime'];
    }
}
