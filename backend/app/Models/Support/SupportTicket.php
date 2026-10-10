<?php

namespace App\Models\Support;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Support\SupportTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['route_stack' => 'array', 'version' => 'integer', 'last_message_at' => 'datetime'];
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'origin_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'recipient_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
