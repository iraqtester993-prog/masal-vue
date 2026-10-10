<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;

    protected $table = 'finance_policy';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'amounts_minor' => 'array', 'daily_limit' => 'integer', 'recovery_hours' => 'integer'];
    }
}
