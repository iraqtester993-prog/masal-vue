<?php

namespace App\Models\Finance;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundingRequest extends Model
{
    protected $attributes = ['version' => 1, 'status' => 'pending', 'purpose' => '', 'reason' => '', 'reference' => ''];

    use HasFactory;

    protected $table = 'finance_funding_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'amount_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id')->select(['id', 'name']);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id')->select(['id', 'name']);
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
