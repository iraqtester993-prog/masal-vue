<?php

namespace App\Models\Sales;

use App\Models\User;
use Database\Factories\Sales\PrintAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintAttempt extends Model
{
    /** @use HasFactory<PrintAttemptFactory> */
    use HasFactory;

    protected $table = 'sales_print_attempts';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->select(['id', 'name']);
    }
}
