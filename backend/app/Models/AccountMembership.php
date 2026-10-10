<?php

namespace App\Models;

use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class AccountMembership extends Model
{
    protected $fillable = ['user_id', 'account_id', 'role_id', 'status', 'kind', 'permission_profile_id', 'include_descendants', 'notes', 'version'];

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean', 'version' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PermissionProfile::class, 'permission_profile_id');
    }

    public function scopeRoots(): array
    {
        return RequestReadCache::remember(
            'AccountMembership.scopeRoots:'.$this->getConnectionName().':'.$this->id,
            fn (): array => $this->resolveScopeRoots(),
        );
    }

    private function resolveScopeRoots(): array
    {
        return DB::table('membership_scope_roots')->where('membership_id', $this->id)->orderBy('account_id')->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function resource(?array $scopeAccounts = null): array
    {
        $user = $this->user;
        $scopeAccounts ??= DB::table('membership_scope_roots')->join('accounts', 'accounts.id', '=', 'account_id')
            ->where('membership_id', $this->id)->orderBy('account_id')->get(['accounts.id', 'accounts.name'])->map(fn ($account): array => ['id' => (int) $account->id, 'name' => $account->name])->all();

        return ['id' => $this->id, 'user_id' => $user->id, 'account_id' => $this->account_id, 'kind' => $this->kind,
            'name' => $user->name, 'login' => $user->login, 'email' => $user->email, 'status' => $this->status,
            'permission_profile_id' => $this->permission_profile_id, 'permission_profile_name' => $this->profile?->name,
            'scope_roots' => array_column($scopeAccounts, 'id'), 'scope_accounts' => $scopeAccounts,
            'include_descendants' => $this->include_descendants, 'notes' => $this->notes, 'version' => $this->version];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function permissions(): array
    {
        return RequestReadCache::remember(
            'AccountMembership.permissions:'.$this->getConnectionName().':'.$this->id,
            fn (): array => $this->resolvePermissions(),
        );
    }

    private function resolvePermissions(): array
    {
        if ($this->kind === 'employee') {
            $profile = PermissionProfile::find($this->permission_profile_id);
            $owner = self::where('account_id', $this->account_id)->where('kind', 'owner')->first();
            if (! $profile || $profile->status !== 'active' || $profile->account_id !== $this->account_id || ! $owner || $owner->status !== 'active') {
                return [];
            }

            return array_values(array_intersect($profile->permissions(), $owner->permissions()));
        }
        $role = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('role_id', $this->role_id)->pluck('permissions.name');
        $overrides = DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('membership_id', $this->id)->get(['permissions.name', 'allowed']);

        $explicitMap = DB::table('account_permission_rules')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('target_account_id', $this->account_id)->where('allowed', true)->where('permissions.name', 'map.view')->pluck('permissions.name');
        $base = $role->merge($explicitMap)->merge($overrides->where('allowed', true)->pluck('name'))
            ->diff($overrides->where('allowed', false)->pluck('name'))
            ->unique()->sort()->values()->all();
        $ancestorIds = DB::table('account_closure')->where('descendant_id', $this->account_id)->pluck('ancestor_id');
        $denied = DB::table('account_permission_rules')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->whereIn('target_account_id', $ancestorIds)->where('allowed', false)->pluck('permissions.name')->all();

        return array_values(array_diff($base, $denied));
    }

    public function isOperational(): bool
    {
        return $this->status === 'active' && $this->account && $this->account->isOperational()
            && ($this->kind !== 'employee' || ($this->profile && $this->profile->status === 'active' && $this->profile->account_id === $this->account_id));
    }
}
