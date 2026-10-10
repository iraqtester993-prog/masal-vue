<?php

namespace App\Models\Maps;

use Illuminate\Database\Eloquent\Model;

class UserPresence extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['session_hash'];

    protected function casts(): array
    {
        return ['connected' => 'boolean', 'sharing' => 'boolean', 'consent_version' => 'integer', 'session_version' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'accuracy' => 'float', 'location_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
