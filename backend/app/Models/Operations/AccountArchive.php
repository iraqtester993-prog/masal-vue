<?php

namespace App\Models\Operations;

use Database\Factories\Operations\AccountArchiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountArchive extends Model
{
    /** @use HasFactory<AccountArchiveFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['before', 'users'];

    protected function casts(): array
    {
        return ['before' => 'encrypted:array', 'users' => 'encrypted:array', 'created_at' => 'datetime'];
    }
}
