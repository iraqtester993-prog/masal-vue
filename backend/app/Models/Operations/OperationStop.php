<?php

namespace App\Models\Operations;

use Database\Factories\Operations\OperationStopFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperationStop extends Model
{
    /** @use HasFactory<OperationStopFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['actions' => 'array', 'scope_roots' => 'array', 'include_descendants' => 'boolean', 'active' => 'boolean', 'version' => 'integer', 'resumed_at' => 'datetime'];
    }
}
