<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Support\AccountPhone;
use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Account extends Model
{
    protected $hidden = ['phone_key'];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->phone_key = $account->type === AccountType::System ? null : AccountPhone::key($account->phone);
        });
    }

    protected $attributes = ['version' => 1, 'device_lock_enabled' => true];

    protected $fillable = ['name', 'type', 'status', 'parent_id', 'city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_lock_enabled', 'device_model', 'app_version', 'notes', 'version'];

    protected function casts(): array
    {
        return ['type' => AccountType::class, 'device_lock_enabled' => 'boolean', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMembership::class);
    }

    public function isOperational(): bool
    {
        return RequestReadCache::remember(
            'Account.isOperational:'.$this->getConnectionName().':'.$this->id,
            fn (): bool => $this->resolveIsOperational(),
        );
    }

    private function resolveIsOperational(): bool
    {
        $ancestors = DB::table('account_closure')->where('descendant_id', $this->id)
            ->join('accounts', 'accounts.id', '=', 'ancestor_id')
            ->get(['accounts.id', 'accounts.parent_id', 'accounts.status', 'accounts.archived_at', 'depth'])->keyBy('id');
        $nextId = $this->id;
        $depth = 0;
        $seen = [];
        while ($nextId !== null) {
            $ancestor = $ancestors->get($nextId);
            if (! $ancestor || isset($seen[$nextId]) || $ancestor->status !== 'active' || $ancestor->archived_at !== null || (int) $ancestor->depth !== $depth) {
                return false;
            }
            $seen[$nextId] = true;
            $nextId = $ancestor->parent_id;
            $depth++;
        }

        return count($seen) === $ancestors->count();
    }
}
