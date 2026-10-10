<?php

namespace App\Models\Backups;

use Database\Factories\Backups\ServerBackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerBackup extends Model
{
    /** @use HasFactory<ServerBackupFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'bytes' => 'integer'];
    }
}
