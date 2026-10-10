<?php

namespace App\Models\Support;

use Database\Factories\Support\SupportOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportOperation extends Model
{
    /** @use HasFactory<SupportOperationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
