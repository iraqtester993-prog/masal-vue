<?php

namespace App\Models\Sales;

use Database\Factories\Sales\DeviceSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceSession extends Model
{
    /** @use HasFactory<DeviceSessionFactory> */
    use HasFactory;

    protected $table = 'sales_device_sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'expires_at' => 'datetime', 'session_version' => 'integer'];
    }
}
