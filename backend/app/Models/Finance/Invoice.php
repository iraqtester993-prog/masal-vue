<?php

namespace App\Models\Finance;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $attributes = ['paid_minor' => 0, 'version' => 1, 'status' => 'unpaid', 'supplier' => ''];

    use HasFactory;

    protected $table = 'finance_invoices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'amount_minor' => 'integer', 'paid_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id')->select(['id', 'name']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id')->select(['id', 'name']);
    }
}
