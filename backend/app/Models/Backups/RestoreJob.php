<?php

namespace App\Models\Backups;

use Database\Factories\Backups\RestoreJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestoreJob extends Model
{
    /** @use HasFactory<RestoreJobFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['scratch', 'rollback'];

    protected function casts(): array
    {
        return ['scratch' => 'encrypted:array', 'rollback' => 'encrypted:array', 'version' => 'integer', 'reviewed_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
