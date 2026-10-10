<?php

namespace App\Models\Operations;

use Database\Factories\Operations\DirectStopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DirectStop extends Model
{
    /** @use HasFactory<DirectStopFactory> */
    use HasFactory;

    protected $table = 'operation_direct_stops';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stops' => 'array', 'version' => 'integer'];
    }
}
