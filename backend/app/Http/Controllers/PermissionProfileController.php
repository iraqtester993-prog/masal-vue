<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountsQueryRequest;
use App\Http\Requests\PermissionProfileRequest;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\SessionRevoker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PermissionProfileController extends Controller
{
    public function index(AccountsQueryRequest $request, int $id, ManagementAuthority $authority, AccountScope $scope): JsonResponse
    {
        $actor = $request->user();
        $assignable = $request->boolean('assignable');
        $actorPermissions = $actor->membership->permissions();
        $permission = $assignable ? (in_array('staff.create', $actorPermissions, true) ? 'staff.create' : 'staff.role') : 'permission_profile.view';
        $authority->account($actor, $id, $permission);
        $owner = AccountMembership::where('account_id', $id)->where('kind', 'owner')->firstOrFail();
        $grantable = $authority->profilePermissions($actor, $owner);
        $query = PermissionProfile::where('account_id', $id)->whereNotExists(function ($members) use ($request, $id, $scope): void {
            $members->selectRaw('1')->from('account_memberships')->whereColumn('permission_profile_id', 'permission_profiles.id')
                ->whereNotIn('id', $scope->members($request->user(), $id)->select('account_memberships.id'));
        });
        if ($assignable) {
            $query->where('status', 'active')->whereNotExists(function ($permissions) use ($grantable): void {
                $permissions->selectRaw('1')->from('permission_profile_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
                    ->whereColumn('permission_profile_id', 'permission_profiles.id')->whereNotIn('permissions.name', $grantable);
            });
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->validated('q').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        $page = $query->orderBy('id')->paginate($request->validated('per_page', 25));
        $profileIds = $page->getCollection()->modelKeys();
        $permissions = DB::table('permission_profile_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
            ->whereIn('permission_profile_id', $profileIds)->orderBy('permissions.name')->get(['permission_profile_id', 'permissions.name'])->groupBy('permission_profile_id');
        $counts = AccountMembership::whereIn('permission_profile_id', $profileIds)->selectRaw('permission_profile_id, count(*) as total')->groupBy('permission_profile_id')->pluck('total', 'permission_profile_id');

        return response()->json(['data' => $page->getCollection()->map(fn (PermissionProfile $profile): array => $this->resource($profile, $actor, ($permissions[$profile->id] ?? collect())->pluck('name')->all(), (int) ($counts[$profile->id] ?? 0), $grantable)), 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()], 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function store(PermissionProfileRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        try {
            $profile = DB::transaction(function () use ($request, $id, $authority, $audit) {
                $actor = $request->user();
                $account = $authority->account($actor, $id, 'permission_profile.create', true, true);
                $authority->require($actor, 'permission_profile.view');
                $data = $request->validated();
                $authority->grants($actor, $data['permissions'], employer: $account);
                $this->ceiling($id, $data['permissions']);
                $profile = PermissionProfile::create(['account_id' => $id, 'name' => $data['name'], 'normalized_name' => mb_strtolower($data['name']), 'status' => 'active', 'version' => 1]);
                $authority->replaceProfilePermissions($profile->id, $data['permissions']);
                $audit->record('permission_profile.create', $request, $actor, $id, ['profile_id' => $profile->id, 'permissions' => $data['permissions']]);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'اسم الصلاحية مستخدم مسبقاً داخل الحساب.']);
        }

        return response()->json(['data' => $this->resource($profile, $request->user())], 201);
    }

    public function update(PermissionProfileRequest $request, int $id, int $profileId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        try {
            $profile = DB::transaction(function () use ($request, $id, $profileId, $authority, $audit, $revoker) {
                $profile = $this->target($request, $id, $profileId, 'permission_profile.update', $authority);
                $data = $request->validated();
                $before = $profile->permissions();
                $permissions = $data['permissions'] ?? $before;
                $authority->grants($request->user(), $permissions, true, $before, employer: $profile->account);
                $changedKeys = $authority->permissionChanges($request->user(), $before, $permissions);
                $added = array_values(array_diff($permissions, $before));
                if ($added) {
                    $this->ceiling($id, $added);
                }
                if (isset($data['name'])) {
                    $profile->name = $data['name'];
                    $profile->normalized_name = mb_strtolower($data['name']);
                }
                $profile->version++;
                $profile->save();
                $authority->replaceProfilePermissions($profile->id, $permissions);
                if (array_diff($changedKeys, ['dashboard.view'])) {
                    $revoker->revoke(AccountMembership::where('permission_profile_id', $profile->id)->pluck('user_id')->all());
                }
                $audit->record('permission_profile.update', $request, $request->user(), $id, ['profile_id' => $profile->id, 'reason' => $data['reason'], 'permissions' => $permissions, 'version' => $profile->version]);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'اسم الصلاحية مستخدم مسبقاً داخل الحساب.']);
        }

        return response()->json(['data' => $this->resource($profile, $request->user())]);
    }

    public function status(PermissionProfileRequest $request, int $id, int $profileId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        $profile = DB::transaction(function () use ($request, $id, $profileId, $authority, $audit, $revoker) {
            $profile = $this->target($request, $id, $profileId, 'permission_profile.toggle', $authority);
            $data = $request->validated();
            if ($data['status'] === 'active') {
                $authority->grants($request->user(), $profile->permissions(), employer: $profile->account);
                $this->ceiling($id, $profile->permissions());
            }
            $profile->status = $data['status'];
            $profile->version++;
            $profile->save();
            $revoker->revoke(AccountMembership::where('permission_profile_id', $profile->id)->pluck('user_id')->all());
            $audit->record('permission_profile.status', $request, $request->user(), $id, ['profile_id' => $profile->id, 'status' => $profile->status, 'reason' => $data['reason'] ?? null, 'version' => $profile->version]);

            return $profile;
        });

        return response()->json(['data' => $this->resource($profile, $request->user())]);
    }

    public function destroy(PermissionProfileRequest $request, int $id, int $profileId, ManagementAuthority $authority, AuditLogger $audit): Response
    {
        DB::transaction(function () use ($request, $id, $profileId, $authority, $audit): void {
            $profile = $this->target($request, $id, $profileId, 'permission_profile.delete', $authority);
            abort_if(AccountMembership::where('permission_profile_id', $profile->id)->exists(), 409, 'نوع الصلاحية مرتبط بموظفين.');
            $audit->record('permission_profile.delete', $request, $request->user(), $id, ['profile_id' => $profile->id, 'reason' => $request->validated('reason')]);
            $profile->delete();
        });

        return response()->noContent();
    }

    private function target(Request $request, int $id, int $profileId, string $permission, ManagementAuthority $authority): PermissionProfile
    {
        $actor = $request->user();
        $authority->account($actor, $id, $permission, true, true);
        $authority->require($actor, 'permission_profile.view');
        $profile = PermissionProfile::where('account_id', $id)->lockForUpdate()->findOrFail($profileId);
        $authority->version($profile, $request->validated('version'));
        abort_if($actor->membership->permission_profile_id === $profile->id, 403, 'لا يمكن تغيير نوع صلاحية حسابك.');
        abort_if(array_diff($profile->permissions(), $actor->membership->permissions()), 403);
        foreach (AccountMembership::where('permission_profile_id', $profile->id)->get() as $member) {
            $authority->member($actor, $member);
        }

        return $profile;
    }

    private function resource(PermissionProfile $profile, User $actor, ?array $permissions = null, ?int $members = null, ?array $grantable = null): array
    {
        $permissions ??= $profile->permissions();
        $members ??= AccountMembership::where('permission_profile_id', $profile->id)->count();
        $actorPermissions = $actor->membership->permissions();
        $grantable ??= app(ManagementAuthority::class)->profilePermissions($actor, AccountMembership::where('account_id', $profile->account_id)->where('kind', 'owner')->firstOrFail());
        $manageable = $actor->membership->permission_profile_id !== $profile->id && ! array_diff($permissions, $actorPermissions);
        $data = $profile->resource(array_values(array_intersect($permissions, $actorPermissions)), $members);
        $data['abilities'] = [
            'update' => $manageable && in_array('permission_profile.view', $actorPermissions, true) && in_array('permission_profile.update', $actorPermissions, true),
            'toggle' => $manageable && in_array('permission_profile.view', $actorPermissions, true) && in_array('permission_profile.toggle', $actorPermissions, true),
            'delete' => $manageable && $members === 0 && in_array('permission_profile.view', $actorPermissions, true) && in_array('permission_profile.delete', $actorPermissions, true),
            'assign' => $profile->status === 'active' && ! array_diff($permissions, $grantable) && (in_array('staff.create', $actorPermissions, true) || in_array('staff.role', $actorPermissions, true)),
        ];

        return $data;
    }

    private function ceiling(int $accountId, array $permissions): void
    {
        $owner = AccountMembership::where('account_id', $accountId)->where('kind', 'owner')->firstOrFail();
        if (array_diff($permissions, $owner->permissions())) {
            throw ValidationException::withMessages(['permissions' => 'الصلاحيات تتجاوز حدود الحساب.']);
        }
    }
}
