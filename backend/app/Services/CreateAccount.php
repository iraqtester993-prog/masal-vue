<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\AccountPhone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateAccount
{
    public function __construct(private AccountHierarchy $hierarchy, private AccountScope $scope, private ManagementAuthority $authority) {}

    /** @param array{name: string, type: string, parent_id: int, user: array{name: string, login: string, email: string, password: string, password_confirmation: string}} $attributes */
    public function execute(User $actor, array $attributes): array
    {
        Gate::forUser($actor)->authorize('create', Account::class);
        try {
            return DB::transaction(function () use ($actor, $attributes): array {
                $parent = $this->scope->query($actor)->findOrFail($attributes['parent_id']);
                $type = AccountType::from($attributes['type']);
                $data = $this->authority->data($actor, $type, $attributes);
                $account = $this->hierarchy->create($attributes['name'], $type, $parent);
                $account->fill($data);
                if ($type === AccountType::Pos) {
                    $account->forceFill(['device_binding_enabled' => true]);
                }
                $account->save();
                if ($type === AccountType::Pos && (array_key_exists('pos_type_id', $attributes) || array_key_exists('representative_ids', $attributes))) {
                    app(ReferencePosProfile::class)->create($actor, $account, $attributes['pos_type_id'] ?? null, $attributes['representative_ids'] ?? []);
                }
                $user = User::create(collect($attributes['user'])->except('password_confirmation')->merge(['name' => $attributes['user']['name'] ?? $account->name, 'status' => 'active'])->all());
                $role = $type->portal() === 'agents' ? 'agent' : 'pos';
                $membership = AccountMembership::create([
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'role_id' => DB::table('roles')->where('name', $role)->value('id'),
                    'status' => 'active',
                ]);
                if ($actor->membership->kind === 'employee') {
                    $excluded = array_diff($membership->permissions(), $actor->membership->permissions());
                    foreach (DB::table('permissions')->whereIn('name', $excluded)->pluck('id') as $permissionId) {
                        DB::table('account_permission_rules')->insert(['target_account_id' => $account->id, 'authority_account_id' => $actor->membership->account_id, 'permission_id' => $permissionId, 'allowed' => false]);
                    }
                }

                return ['account' => $account, 'user' => $user];
            });
        } catch (UniqueConstraintViolationException $failure) {
            if (AccountPhone::duplicateViolation($failure)) {
                throw ValidationException::withMessages(['phone' => 'رقم الهاتف مستخدم في حساب آخر؛ أدخل رقمًا مختلفًا.']);
            }
            throw ValidationException::withMessages(['user.login' => 'بيانات المستخدم مستخدمة مسبقاً.']);
        }
    }
}
