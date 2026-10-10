<?php

namespace App\Models\Finance;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $attributes = ['balance_minor' => 0, 'held_minor' => 0, 'version' => 1, 'kind' => 'account'];

    use HasFactory;

    protected $table = 'finance_wallets';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'balance_minor' => 'integer', 'held_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id')->select(['id', 'name']);
    }
}
