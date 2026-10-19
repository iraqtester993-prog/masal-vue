<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AccountPrivacy
{
    public function ownerIds(): Builder
    {
        return DB::table('account_memberships as private_member')->join('accounts as private_account', 'private_account.id', '=', 'private_member.account_id')
            ->where('private_member.kind', 'owner')->where('private_account.type', 'system')->select('private_member.user_id');
    }

    public function excludeOwner(Builder|EloquentBuilder $query, string $column): Builder|EloquentBuilder
    {
        return $query->where(fn ($rows) => $rows->whereNull($column)->orWhereNotIn($column, $this->ownerIds()));
    }

    public function users(): Builder
    {
        return DB::table('users')->whereNotIn('users.id', $this->ownerIds())->select(['users.id', 'users.name', 'users.email', 'users.status']);
    }

    public function accounts(): Builder
    {
        return DB::table('accounts')->select(['id', 'type', 'parent_id', 'status', 'city', 'support', 'color', 'address', 'serial', 'device_model', 'app_version', 'notes', 'version', 'archived_at', 'created_at', 'updated_at', 'device_lock_enabled', 'device_binding_enabled'])
            ->selectRaw("CASE WHEN type='system' THEN 'الحساب المركزي' ELSE name END AS name")
            ->selectRaw("CASE WHEN type='system' THEN NULL ELSE phone END AS phone")
            ->selectRaw("CASE WHEN type='system' THEN NULL ELSE owner_name END AS owner_name");
    }

    public function isOwner(?int $id): bool
    {
        if ($id === null) {
            return false;
        }
        $ids = RequestReadCache::remember('AccountPrivacy.ownerIds', fn (): array => $this->ownerIds()->pluck('private_member.user_id')->map(fn ($value): int => (int) $value)->all());

        return in_array($id, $ids, true);
    }

    public function userId(?int $id): ?int
    {
        return $this->isOwner($id) ? null : $id;
    }

    public function userName(?User $user, string $service = 'الخدمة'): ?string
    {
        return $user ? ($this->isOwner($user->id) ? $service : $user->name) : null;
    }

    public function accountName(?Account $account, string $central = 'الحساب المركزي'): ?string
    {
        if (! $account) {
            return null;
        }
        $system = $account->type === AccountType::System;
        if (! array_key_exists('type', $account->getAttributes())) {
            $ids = RequestReadCache::remember('AccountPrivacy.systemAccountIds', fn (): array => DB::table('accounts')->where('type', 'system')->pluck('id')->map(fn ($value): int => (int) $value)->all());
            $system = in_array($account->id, $ids, true);
        }

        return $system ? $central : $account->name;
    }

    public function accountData(Account $account): array
    {
        $data = array_replace($account->toArray(), ['name' => $this->accountName($account)]);
        if ($account->type === AccountType::System) {
            foreach (['phone', 'city', 'owner_name', 'address', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }
}
