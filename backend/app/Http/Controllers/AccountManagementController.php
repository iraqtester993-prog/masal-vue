<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\AccountDataRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\PermissionCatalog;
use App\Services\ReferencePosProfile;
use App\Services\SessionRevoker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AccountManagementController extends Controller
{
    public function update(AccountDataRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): AccountResource
    {
        try {
            $account = DB::transaction(function () use ($request, $id, $authority, $audit, $revoker) {
                $account = $authority->account($request->user(), $id, 'account.update', false, true);
                $data = $request->validated();
                $authority->version($account, $data['version']);
                $attributes = $authority->data($request->user(), $account->type, $data, $account);
                $account->fill($attributes);
                if (array_key_exists('pos_type_id', $data) || array_key_exists('representative_ids', $data)) {
                    if ($account->type !== AccountType::Pos) {
                        throw ValidationException::withMessages(['type' => 'حقول المندوبين والنوع خاصة بنقطة البيع.']);
                    }
                    $oldType = DB::table('pos_reference_profiles')->where('account_id', $id)->value('pos_type_id');
                    $oldIds = DB::table('pos_representatives')->where('account_id', $id)->pluck('representative_id')->map(fn ($value): int => (int) $value)->all();
                    app(ReferencePosProfile::class)->write($request->user(), $account, array_key_exists('pos_type_id', $data) ? $data['pos_type_id'] : ($oldType ? (int) $oldType : null), $data['representative_ids'] ?? $oldIds);
                    $attributes = array_merge($attributes, array_intersect_key($data, array_flip(['pos_type_id', 'representative_ids'])));
                }
                $account->version++;
                $account->save();
                if (isset($attributes['name'])) {
                    $owner = AccountMembership::where('account_id', $id)->where('kind', 'owner')->first();
                    $owner?->user()->update(['name' => $attributes['name']]);
                }
                if (array_key_exists('login', $data)) {
                    $this->changeOwnerLogin($request, $account, $data, $authority, $audit, $revoker);
                }
                $audit->record('account.update', $request, $request->user(), $id, ['reason' => $data['reason'] ?? null, 'fields' => array_keys($attributes), 'version' => $account->version]);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['serial' => 'الرقم التسلسلي مستخدم مسبقاً.']);
        }

        return new AccountResource($account);
    }

    public function status(AccountDataRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): AccountResource
    {
        $account = DB::transaction(function () use ($request, $id, $authority, $audit, $revoker) {
            $account = $authority->account($request->user(), $id, 'account.toggle', false, true);
            $data = $request->validated();
            $authority->version($account, $data['version']);
            $account->status = $data['status'];
            $account->version++;
            $account->save();
            if ($account->status === 'disabled') {
                $descendants = DB::table('account_closure')->where('ancestor_id', $id)->pluck('descendant_id');
                $revoker->revoke(AccountMembership::whereIn('account_id', $descendants)->pluck('user_id')->all());
            }
            $audit->record('account.status', $request, $request->user(), $id, ['status' => $account->status, 'reason' => $data['reason'], 'version' => $account->version]);

            return $account;
        });

        return new AccountResource($account);
    }

    public function permissions(AccountDataRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): AccountResource
    {
        $account = DB::transaction(function () use ($request, $id, $authority, $audit, $revoker) {
            $actor = $request->user();
            $account = $authority->account($actor, $id, 'account.permissions', false, true);
            $data = $request->validated();
            $authority->version($account, $data['version']);
            $owner = AccountMembership::where('account_id', $id)->where('kind', 'owner')->firstOrFail();
            $before = $owner->permissions();
            $authority->grants($actor, $data['permissions'], false, $before, $owner);
            $changedKeys = $authority->permissionChanges($actor, $before, $data['permissions'], $owner);
            $added = array_values(array_diff($data['permissions'], $before));
            $authorityId = $actor->membership->account_id;
            $higherIds = DB::table('account_closure')->where('descendant_id', $authorityId)->where('depth', '>', 0)->pluck('ancestor_id');
            $blocked = DB::table('account_permission_rules')->join('permissions', 'permissions.id', '=', 'permission_id')
                ->where('target_account_id', $id)->whereIn('authority_account_id', $higherIds)->where('allowed', false)->pluck('permissions.name')->all();
            if (array_intersect($data['permissions'], $blocked)) {
                throw ValidationException::withMessages(['permissions' => 'لا يمكن إزالة منع صادر من جهة أعلى.']);
            }
            if (array_diff($added, $authority->permissionLimits($actor, $owner))) {
                throw ValidationException::withMessages(['permissions' => 'الصلاحيات المطلوبة تتجاوز حدود التفويض للحساب.']);
            }
            $roleCeiling = $authority->roleCeiling($owner);
            if (array_diff($added, $roleCeiling)) {
                throw ValidationException::withMessages(['permissions' => 'هذه الصلاحيات غير متاحة لنوع الحساب.']);
            }
            $lowerAuthorities = DB::table('account_closure')->where('ancestor_id', $authorityId)->where('depth', '>', 0)->pluck('descendant_id');
            $changedPermissions = DB::table('permissions')->whereIn('name', $changedKeys)->get(['id', 'name']);
            DB::table('account_permission_rules')->where('target_account_id', $id)->whereIn('authority_account_id', $lowerAuthorities)->whereIn('permission_id', $changedPermissions->pluck('id'))->delete();
            foreach ($changedPermissions as $permission) {
                DB::table('account_permission_rules')->updateOrInsert(
                    ['target_account_id' => $id, 'authority_account_id' => $authorityId, 'permission_id' => $permission->id],
                    ['allowed' => in_array($permission->name, $data['permissions'], true)],
                );
            }
            $account->version++;
            $account->save();
            $descendants = DB::table('account_closure')->where('ancestor_id', $id)->pluck('descendant_id');
            if (array_diff($changedKeys, ['dashboard.view'])) {
                $revoker->revoke(AccountMembership::whereIn('account_id', $descendants)->pluck('user_id')->all());
            }
            $audit->record('account.permissions', $request, $actor, $id, ['permissions' => $data['permissions'], 'changed_keys' => $changedKeys, 'reason' => $data['reason'], 'authority_account_id' => $authorityId, 'version' => $account->version]);

            return $account;
        });

        return new AccountResource($account);
    }

    public function login(AccountDataRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): AccountResource
    {
        try {
            $account = DB::transaction(function () use ($request, $id, $authority, $audit, $revoker) {
                $actor = $request->user();
                $account = $authority->account($actor, $id, 'account.login', false, true);
                abort_unless($actor->membership->account->type === AccountType::System && $actor->membership->kind === 'owner', 403);
                $data = $request->validated();
                $authority->version($account, $data['version']);
                $account->version++;
                $account->save();
                $this->changeOwnerLogin($request, $account, $data, $authority, $audit, $revoker);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['login' => 'معرف الدخول مستخدم مسبقاً.']);
        }

        return new AccountResource($account);
    }

    private function changeOwnerLogin(Request $request, Account $account, array $data, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): void
    {
        $actor = $request->user();
        $authority->require($actor, 'account.login');
        abort_unless($actor->membership->account->type === AccountType::System && $actor->membership->kind === 'owner', 403);
        $id = $account->id;
        try {
            $identifier = mb_strtolower(trim($data['login']));
            $email = str_contains($identifier, '@');
            Validator::make(['login' => $identifier], ['login' => $email ? ['required', 'email', 'max:190'] : ['required', 'regex:/^[a-z][a-z0-9._-]{2,63}$/']])->validate();
            $owner = AccountMembership::where('account_id', $id)->where('kind', 'owner')->lockForUpdate()->firstOrFail();
            $user = $owner->user()->lockForUpdate()->firstOrFail();
            $column = $email ? 'email' : 'login';
            if (User::where($column, $identifier)->where('id', '<>', $user->id)->exists()) {
                throw ValidationException::withMessages(['login' => 'معرف الدخول مستخدم مسبقاً.']);
            }
            $user->{$column} = $identifier;
            if ($email) {
                $user->login = null;
            } else {
                $user->email = null;
            }
            $user->save();
            $revoker->revoke([$user->id]);
            $audit->record('account.login', $request, $actor, $id, ['reason' => $data['reason'], 'subject_user_id' => $user->id, 'version' => $account->version]);

        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['login' => 'معرف الدخول مستخدم مسبقاً.']);
        }
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => ['cities' => DB::table('account_cities')->where('active', true)->orderBy('id')->pluck('name')]]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => PermissionCatalog::rows()]);
    }
}
