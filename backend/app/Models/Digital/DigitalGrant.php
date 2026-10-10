<?php

namespace App\Models\Digital;

use App\Models\Account;
use Database\Factories\Digital\DigitalGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DigitalGrant extends Model
{
    /** @use HasFactory<DigitalGrantFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer', 'offer_ids' => 'array'];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'target_account_id');
    }
}
