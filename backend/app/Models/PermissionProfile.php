<?php

namespace App\Models;

use Database\Factories\PermissionProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class PermissionProfile extends Model
{
    /** @use HasFactory<PermissionProfileFactory> */
    use HasFactory;

    protected $fillable = ['account_id', 'name', 'normalized_name', 'status', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function permissions(): array
    {
        return DB::table('permission_profile_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('permission_profile_id', $this->id)->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    public function resource(?array $permissions = null, ?int $membersCount = null): array
    {
        return ['id' => $this->id, 'account_id' => $this->account_id, 'name' => $this->name,
            'permissions' => $permissions ?? $this->permissions(), 'status' => $this->status, 'version' => $this->version,
            'members_count' => $membersCount ?? AccountMembership::where('permission_profile_id', $this->id)->count(), 'builtin' => false];
    }
}
