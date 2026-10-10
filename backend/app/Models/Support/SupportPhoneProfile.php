<?php

namespace App\Models\Support;

use Database\Factories\Support\SupportPhoneProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupportPhoneProfile extends Model
{
    /** @use HasFactory<SupportPhoneProfileFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['phones' => 'array', 'version' => 'integer'];
    }
}
