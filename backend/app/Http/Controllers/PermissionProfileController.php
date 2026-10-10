<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountsQueryRequest;
use App\Http\Requests\PermissionProfileRequest;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
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
        $authority->account($request->user(), $id, 'permission_profile.view');
        $query = PermissionProfile::where('account_id', $id)->whereNotExists(function ($members) use ($request, $id, $scope): void {
            $members->selectRaw('1')->from('account_memberships')->whereColumn('permission_profile_id', 'permission_profiles.id')
                ->whereNotIn('id', $scope->members($request->user(), $id)->select('account_memberships.id'));
        });
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

        return response()->json(['data' => $page->getCollection()->map(fn (PermissionProfile $profile): array => $profile->resource(($permissions[$profile->id] ?? collect())->pluck('name')->all(), (int) ($counts[$profile->id] ?? 0))), 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()], 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function store(PermissionProfileRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        try {
            $profile = DB::transaction(function () use ($request, $id, $authority, $audit) {
                $actor = $request->user();
                $authority->account($actor, $id, 'permission_profile.create', true, true);
                $authority->require($actor, 'permission_profile.view');
                $data = $request->validated();
                $authority->grants($actor, $data['permissions']);
                $this->ceiling($id, $data['permissions']);
                $profile = PermissionProfile::create(['account_id' => $id, 'name' => $data['name'], 'normalized_name' => mb_strtolower($data['name']), 'status' => 'active', 'version' => 1]);
                $authority->replaceProfilePermissions($profile->id, $data['permissions']);
                $audit->record('permission_profile.create', $request, $actor, $id, ['profile_id' => $profile->id, 'permissions' => $data['permissions']]);

                return $profile;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'اسم الصلاحية مستخدم مسبقاً داخل الحساب.']);
        }

        return response()->json(['data' => $profile->resource()], 201);
    }

    public function update(PermissionProfileRequest $request, int $id, int $profileId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        try {
            $profile = DB::transaction(function () use ($request, $id, $profileId, $authority, $audit, $revoker) {
                $profile = $this->target($request, $id, $profileId, 'permission_profile.update', $authority);
                $data = $request->validated();
                $before = $profile->permissions();
                $permissions = $data['permissions'] ?? $before;
                $authority->grants($request->user(), $permissions, true, $before);
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

        return response()->json(['data' => $profile->resource()]);
    }

    public function status(PermissionProfileRequest $request, int $id, int $profileId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        $profile = DB::transaction(function () use ($request, $id, $profileId, $authority, $audit, $revoker) {
            $profile = $this->target($request, $id, $profileId, 'permission_profile.toggle', $authority);
            $data = $request->validated();
            if ($data['status'] === 'active') {
                $authority->grants($request->user(), $profile->permissions());
                $this->ceiling($id, $profile->permissions());
            }
            $profile->status = $data['status'];
            $profile->version++;
            $profile->save();
            $revoker->revoke(AccountMembership::where('permission_profile_id', $profile->id)->pluck('user_id')->all());
            $audit->record('permission_profile.status', $request, $request->user(), $id, ['profile_id' => $profile->id, 'status' => $profile->status, 'reason' => $data['reason'] ?? null, 'version' => $profile->version]);

            return $profile;
        });

        return response()->json(['data' => $profile->resource()]);
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
        foreach (AccountMembership::where('permission_profile_id', $profile->id)->get() as $member) {
            $authority->member($actor, $member);
        }

        return $profile;
    }

    private function ceiling(int $accountId, array $permissions): void
    {
        $owner = AccountMembership::where('account_id', $accountId)->where('kind', 'owner')->firstOrFail();
        if (array_diff($permissions, $owner->permissions())) {
            throw ValidationException::withMessages(['permissions' => 'الصلاحيات تتجاوز حدود الحساب.']);
        }
    }
}
