<?php

namespace App\Models\Finance;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRequest extends Model
{
    protected $attributes = ['version' => 1, 'status' => 'pending', 'reason' => ''];

    use HasFactory;

    protected $table = 'finance_price_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'changes' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id')->select(['id', 'name']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'name']);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id')->select(['id', 'name']);
    }
}
