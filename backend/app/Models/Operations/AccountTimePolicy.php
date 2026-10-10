<?php

namespace App\Models\Operations;

use Database\Factories\Operations\AccountTimePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountTimePolicy extends Model
{
    /** @use HasFactory<AccountTimePolicyFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['policy' => 'array', 'version' => 'integer'];
    }
}
