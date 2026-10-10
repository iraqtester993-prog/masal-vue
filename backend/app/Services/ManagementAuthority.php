<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Http\Requests\AccountDataRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use App\Support\AccountPhone;
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
        if (in_array(DB::table('roles')->where('id', $owner->role_id)->value('name'), ['agent', 'pos'], true)) {
            $permissions[] = 'map.view';
        }

        return array_values(array_unique($permissions));
    }

    private function delegablePermissions(User $actor, ?AccountMembership $owner = null): array
    {
        $permissions = $actor->membership->permissions();
        if ($owner?->account->type === AccountType::Pos && in_array('digital.assign', $permissions, true)) {
            $permissions = array_merge($permissions, ['digital.create', 'digital.receipt']);
        }

        return array_values(array_unique($permissions));
    }

    /** @param array<string> $permissions */
    public function grants(User $actor, array $permissions, bool $requireView = true, array $existing = [], ?AccountMembership $owner = null): void
    {
        $unknown = array_diff($permissions, array_keys(PermissionCatalog::LABELS));
        $excess = array_diff($permissions, $this->delegablePermissions($actor, $owner), $existing);
        if ($unknown || $excess || ($requireView && ! in_array('account.view', $permissions, true))) {
            throw ValidationException::withMessages(['permissions' => 'اختر صلاحيات معروفة تملكها، مع صلاحية عرض الحسابات.']);
        }
        foreach ($permissions as $permission) {
            $module = explode('.', $permission)[0];
            if ($module === 'sell' && ! in_array('sales.view', $permissions, true)) {
                throw ValidationException::withMessages(['permissions' => 'عرض المبيعات مطلوب قبل إجراءات البيع والطباعة.']);
            }
            if ($permission === 'agents.archive' && ! in_array('agents.archiveView', $permissions, true)) {
                throw ValidationException::withMessages(['permissions' => 'عرض الأرشيف مطلوب قبل أرشفة الحسابات.']);
            }
            if (! str_ends_with($permission, '.view') && in_array($module, ['staff', 'permission_profile', 'products', 'providers', 'governorates', 'sources', 'posTypes', 'representatives', 'wallets', 'ledger', 'invoices', 'prices', 'import', 'inventory', 'claims', 'exports', 'sales', 'exceptions', 'branding', 'support', 'notifications', 'reports', 'digital', 'integrations', 'company', 'backup'], true) && ! in_array($module.'.view', $permissions, true)) {
                throw ValidationException::withMessages(['permissions' => 'صلاحية العرض مطلوبة قبل إجراءات القسم.']);
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
        }
        if ($member->kind === 'employee') {
            $this->scope($actor, $member->account, $member->scopeRoots(), $member->include_descendants);
        }
    }

    public function data(User $actor, AccountType $type, array $attributes, ?Account $old = null): array
    {
        $data = array_intersect_key($attributes, array_flip(AccountDataRequest::FIELDS));
        $merged = array_merge($old?->only(AccountDataRequest::FIELDS) ?? [], $data);
        $required = $type === AccountType::Pos ? ['name', 'owner_name', 'city', 'address', 'phone', 'device_model', 'app_version'] : ['name', 'city', 'phone', 'color'];
        if ($type === AccountType::Pos && ($merged['device_lock_enabled'] ?? true)) {
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
            $key = AccountPhone::key($data['phone']);
            if ($key !== null && (! $old || $key !== AccountPhone::key($old->phone)) && Account::where('phone_key', $key)->when($old, fn ($query) => $query->where('id', '<>', $old->id))->exists()) {
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
