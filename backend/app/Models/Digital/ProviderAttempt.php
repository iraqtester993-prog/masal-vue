<?php

namespace App\Models\Digital;

use Database\Factories\Digital\ProviderAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderAttempt extends Model
{
    /** @use HasFactory<ProviderAttemptFactory> */
    use HasFactory;

    protected $table = 'digital_provider_attempts';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
