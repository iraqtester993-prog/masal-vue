<?php

namespace App\Models\Sales;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Sales\ReprintRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReprintRequest extends Model
{
    /** @use HasFactory<ReprintRequestFactory> */
    use HasFactory;

    protected $table = 'sales_reprint_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'history' => 'array', 'reviewed_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'name']);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'recipient_id')->select(['id', 'name']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'name']);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id')->select(['id', 'name']);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
