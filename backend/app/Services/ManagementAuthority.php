<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Http\Requests\AccountDataRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use App\Support\AccountPhone;
use App\Support\RequestReadCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManagementAuthority
{
    public function __construct(private AccountScope $scope) {}

    public function require(User $actor, string $permission): void
    {
        abort_unless($actor->isOperational() && in_array($permission, $actor->membership->permissions(), true), 403);
    }

    public function hasSystemScope(User $actor): bool
    {
        return RequestReadCache::remember('ManagementAuthority.systemScope:'.$actor->id, fn (): bool => $actor->isOperational() && $actor->membership->account->type === AccountType::System
            && $this->scope->query($actor)->whereKey($actor->membership->account_id)->exists());
    }

    public function hasFullSystemScope(User $actor): bool
    {
        return RequestReadCache::remember('ManagementAuthority.fullSystemScope:'.$actor->id, fn (): bool => $this->hasSystemScope($actor)
            && ($actor->membership->kind === 'owner' || ! Account::whereNotIn('id', $this->scope->query($actor, true)->select('accounts.id'))->exists()));
    }

    public function systemAccess(User $actor, string $permission): void
    {
        $this->require($actor, $permission);
        abort_unless($this->hasSystemScope($actor), 403);
    }

    public function profilePermissions(User $actor, AccountMembership $owner): array
    {
        return array_values(array_filter(array_intersect($actor->membership->permissions(), $owner->permissions()), fn (string $permission): bool => PermissionCatalog::employeeAllowed($permission, $owner->account->type)));
    }

    public function preserveHiddenPermissions(User $actor, array $before, array $submitted, ?AccountMembership $owner = null): array
    {
        return array_values(array_unique(array_merge($submitted, array_diff($before, $this->delegablePermissions($actor, $owner)))));
    }

    public function account(User $actor, int $id, string $permission, bool $allowSelf = true, bool $lock = false): Account
    {
        if ($lock) {
            app(MutationGuard::class)->lock($actor, [$id], $permission);
        }
        $this->require($actor, $permission);
        $query = $this->scope->query($actor);
        $account = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        if (! $allowSelf) {
            abort_if($account->id === $actor->membership->account_id || $account->type === AccountType::System, 403, 'لا يمكن تغيير حسابك أو الحساب الأعلى.');
        }

        return $account;
    }

    public function version(Model $record, int $version): void
    {
        abort_unless((int) $record->version === $version, 409, 'تغيرت البيانات؛ أعد فتحها قبل الحفظ.');
    }

    public function permissionLimits(User $actor, AccountMembership $owner): array
    {
        $roleCeiling = $this->roleCeiling($owner);
        $higherAuthorities = DB::table('account_closure')->where('descendant_id', $actor->membership->account_id)->where('depth', '>', 0)->pluck('ancestor_id');
        $parents = DB::table('account_closure')->where('descendant_id', $owner->account_id)->where('depth', '>', 0)->pluck('ancestor_id');
        $blocked = DB::table('account_permission_rules')->join('permissions', 'permissions.id', '=', 'permission_id')->where('allowed', false)
            ->where(function ($rules) use ($parents, $higherAuthorities, $owner): void {
                $rules->whereIn('target_account_id', $parents)->orWhere(function ($target) use ($higherAuthorities, $owner): void {
                    $target->where('target_account_id', $owner->account_id)->whereIn('authority_account_id', $higherAuthorities);
                });
            })->pluck('permissions.name')->all();
        $personalDenied = DB::table('membership_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->where('membership_id', $owner->id)->where('allowed', false)->pluck('permissions.name')->all();
        $blocked = array_unique(array_merge($blocked, $personalDenied));

        return array_values(array_diff(array_intersect($this->delegablePermissions($actor, $owner), $roleCeiling), $blocked));
    }

    public function roleCeiling(AccountMembership $owner): array
    {
        $permissions = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')->where('role_id', $owner->role_id)->pluck('permissions.name')->all();
        $roleName = DB::table('roles')->where('id', $owner->role_id)->value('name');
        if (in_array($roleName, ['agent', 'pos'], true)) {
            $permissions[] = 'map.view';
        }
        if ($roleName === 'agent') {
            $permissions[] = 'staff.password_reset';
        }

        return array_values(array_unique($permissions));
    }

    private function delegablePermissions(User $actor, ?AccountMembership $owner = null): array
    {
        $permissions = $actor->membership->permissions();
        if ($owner?->account->type === AccountType::Pos && in_array('digital.assign', $permissions, true)) {
            $permissions = array_merge($permissions, ['digital.create', 'digital.receipt', 'digital.print']);
        }

        return array_values(array_unique($permissions));
    }

    /** @param array<string> $permissions */
    public function grants(User $actor, array $permissions, bool $requireView = true, array $existing = [], ?AccountMembership $owner = null, ?Account $employer = null): void
    {
        $unknown = array_diff($permissions, array_keys(PermissionCatalog::LABELS));
        $excess = array_diff($permissions, $this->delegablePermissions($actor, $owner), $existing);
        if ($unknown || $excess) {
            throw ValidationException::withMessages(['permissions' => 'تعذر اعتماد مجموعة الصلاحيات المطلوبة.']);
        }
        foreach ($permissions as $permission) {
            if (array_diff(PermissionCatalog::dependencies($permission), $permissions)) {
                throw ValidationException::withMessages(['permissions' => 'صلاحية العرض مطلوبة قبل إجراءات القسم.']);
            }
            if ($requireView && ! PermissionCatalog::employeeAllowed($permission, $employer?->type ?? $actor->membership->account->type) && ! in_array($permission, $existing, true)) {
                throw ValidationException::withMessages(['permissions' => 'هذه الصلاحية محصورة بمالك الحساب ولا تمنح للموظف.']);
            }
        }
    }

    public function permissionChanges(User $actor, array $before, array $after, ?AccountMembership $owner = null): array
    {
        $changed = array_values(array_unique(array_merge(array_diff($before, $after), array_diff($after, $before))));
        if (array_diff($changed, $this->delegablePermissions($actor, $owner))) {
            throw ValidationException::withMessages(['permissions' => 'لا يمكن تغيير صلاحية لا تملك تفويضها.']);
        }

        return $changed;
    }

    /** @param array<int> $roots */
    public function scope(User $actor, Account $employer, array $roots, bool $descendants): void
    {
        if ($roots === []) {
            throw ValidationException::withMessages(['scope_roots' => 'اختر نطاقاً واحداً على الأقل.']);
        }
        $employerIds = DB::table('account_closure')->where('ancestor_id', $employer->id)->pluck('descendant_id')->all();
        $expanded = $descendants
            ? DB::table('account_closure')->whereIn('ancestor_id', $roots)->pluck('descendant_id')->unique()->all() : $roots;
        $visibleIds = $this->scope->query($actor)->whereIn('id', $expanded)->pluck('id')->all();
        if (array_diff($roots, $employerIds) || array_diff($expanded, $visibleIds) || count($expanded) === 0) {
            throw ValidationException::withMessages(['scope_roots' => 'نطاق الموظف يجب أن يكون داخل الحساب ونطاق المنفذ.']);
        }
    }

    public function member(User $actor, AccountMembership $member, bool $mutation = true): void
    {
        if ($mutation) {
            abort_if($member->user_id === $actor->id || $member->kind !== 'employee', 403, 'هذا المستخدم محمي من التعديل.');
            abort_if($actor->membership->kind === 'employee' && array_diff(array_unique(array_merge($member->permissions(), $member->profile?->permissions() ?? [])), $actor->membership->permissions()), 403);
        }
        if ($member->kind === 'employee') {
            $this->scope($actor, $member->account, $member->scopeRoots(), $member->include_descendants);
        }
    }

    public function data(User $actor, AccountType $type, array $attributes, ?Account $old = null): array
    {
        $data = array_intersect_key($attributes, array_flip(AccountDataRequest::FIELDS));
        $merged = array_merge($old?->only(AccountDataRequest::FIELDS) ?? [], $data);
        $required = $type === AccountType::Pos ? ['name', 'owner_name', 'city', 'address', 'phone'] : ['name', 'city', 'phone', 'color'];
        if ($type === AccountType::Pos && $old && ! $old->device_binding_enabled && ($merged['device_lock_enabled'] ?? true)) {
            $required[] = 'serial';
        }
        Validator::make($merged, collect($required)->mapWithKeys(fn (string $field): array => [$field => ['required', 'string']])->all())->validate();
        if (array_key_exists('city', $data) && (! $old || $old->city !== $data['city']) && ! DB::table('account_cities')->where('name', $data['city'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['city' => 'اختر محافظة مفعلة.']);
        }
        $posFields = ['owner_name', 'address', 'serial', 'device_lock_enabled', 'device_model', 'app_version'];
        if ($type !== AccountType::Pos && array_intersect(array_keys($data), $posFields)) {
            throw ValidationException::withMessages(['type' => 'حقول نقطة البيع غير مسموحة للوكيل.']);
        }
        if ($type === AccountType::Pos) {
            foreach (['pos.device' => ['serial', 'device_lock_enabled', 'device_model', 'app_version'], 'pos.location' => ['city', 'address']] as $permission => $fields) {
                foreach ($fields as $field) {
                    if (array_key_exists($field, $data) && (! $old || $old->{$field} !== $data[$field])) {
                        $this->require($actor, $permission);
                    }
                }
            }
        }
        if (isset($data['serial'])) {
            $data['serial'] = trim($data['serial']) === '' ? null : mb_strtoupper(trim($data['serial']));
            if ($data['serial'] !== null && Account::where('serial', $data['serial'])->when($old, fn ($query) => $query->where('id', '<>', $old->id))->exists()) {
                throw ValidationException::withMessages(['serial' => 'الرقم التسلسلي مستخدم مسبقاً.']);
            }
        }

        if (isset($data['phone'])) {
            $legacyUnchanged = $old && $data['phone'] === $old->phone && preg_match('/^9647[78][0-9]{8}$/D', AccountPhone::key($old->phone) ?? '');
            if (! $legacyUnchanged) {
                $phone = AccountPhone::local($data['phone']);
                if ($phone === null) {
                    throw ValidationException::withMessages(['phone' => 'أدخل رقم هاتف من 11 رقمًا يبدأ بـ 077 أو 078 فقط.']);
                }
                $data['phone'] = $phone;
            }
            $key = AccountPhone::key($data['phone']);
            if ($key !== null && Account::where('phone_key', $key)->when($old, fn ($query) => $query->where('id', '<>', $old->id))->exists()) {
                throw ValidationException::withMessages(['phone' => 'رقم الهاتف مستخدم في حساب آخر؛ أدخل رقمًا مختلفًا.']);
            }
        }

        return $data;
    }

    public function replaceProfilePermissions(int $profileId, array $permissions): void
    {
        DB::table('permission_profile_permissions')->where('permission_profile_id', $profileId)->delete();
        foreach (DB::table('permissions')->whereIn('name', $permissions)->pluck('id') as $id) {
            DB::table('permission_profile_permissions')->insert(['permission_profile_id' => $profileId, 'permission_id' => $id]);
        }
    }
}
