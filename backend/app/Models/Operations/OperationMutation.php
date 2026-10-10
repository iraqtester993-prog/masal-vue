<?php

namespace App\Models\Operations;

use Illuminate\Database\Eloquent\Model;

class OperationMutation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['fingerprint'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
