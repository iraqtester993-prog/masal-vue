<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountsQueryRequest;
use App\Http\Requests\StaffRequest;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\SessionRevoker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffController extends Controller
{
    public function index(AccountsQueryRequest $request, int $id, ManagementAuthority $authority, AccountScope $scope): JsonResponse
    {
        $authority->account($request->user(), $id, 'staff.view');
        $query = $scope->members($request->user(), $id)->with(['user', 'profile:id,name']);
        if ($request->filled('q')) {
            $q = $request->validated('q');
            $query->whereHas('user', fn ($builder) => $builder->where(fn ($names) => $names->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        $page = $query->orderBy('id')->paginate($request->validated('per_page', 25));
        $roots = DB::table('membership_scope_roots')->join('accounts', 'accounts.id', '=', 'account_id')->whereIn('membership_id', $page->getCollection()->modelKeys())
            ->orderBy('account_id')->get(['membership_id', 'accounts.id', 'accounts.name'])->groupBy('membership_id');

        return response()->json(['data' => $page->getCollection()->map(fn (AccountMembership $member): array => $member->resource(($roots[$member->id] ?? collect())->map(fn ($account): array => ['id' => (int) $account->id, 'name' => $account->name])->all())), 'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()], 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()]]);
    }

    public function store(StaffRequest $request, int $id, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        try {
            $member = DB::transaction(function () use ($request, $id, $authority, $audit) {
                $actor = $request->user();
                $account = $authority->account($actor, $id, 'staff.create', true, true);
                $data = $request->validated();
                $profile = $this->profile($actor, $account, $data['permission_profile_id'], $authority);
                $authority->scope($actor, $account, $data['scope_roots'], $data['include_descendants']);
                $user = User::create(['name' => $data['name'], 'login' => $data['login'] ?? null, 'email' => $data['email'], 'password' => $data['password'], 'status' => 'active']);
                $member = AccountMembership::create(['user_id' => $user->id, 'account_id' => $id,
                    'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'kind' => 'employee',
                    'permission_profile_id' => $profile->id, 'status' => 'active',
                    'include_descendants' => $data['include_descendants'], 'notes' => $data['notes'] ?? null, 'version' => 1]);
                $this->roots($member, $data['scope_roots']);
                $audit->record('staff.create', $request, $actor, $id, ['membership_id' => $member->id, 'profile_id' => $profile->id, 'scope_roots' => $data['scope_roots']]);

                return $member;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'بيانات المستخدم مستخدمة مسبقاً.']);
        }

        return response()->json(['data' => $member->fresh()->resource()], 201);
    }

    public function update(StaffRequest $request, int $id, int $memberId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        try {
            $member = DB::transaction(function () use ($request, $id, $memberId, $authority, $audit, $revoker) {
                $actor = $request->user();
                $account = $authority->account($actor, $id, 'staff.update', true, true);
                $member = AccountMembership::where('account_id', $id)->lockForUpdate()->findOrFail($memberId);
                $authority->member($actor, $member);
                $data = $request->validated();
                $authority->version($member, $data['version']);
                if (array_intersect(array_keys($data), ['scope_roots', 'include_descendants'])) {
                    $authority->require($actor, 'staff.scope');
                }
                if (isset($data['permission_profile_id'])) {
                    $authority->require($actor, 'staff.role');
                    $member->permission_profile_id = $this->profile($actor, $account, $data['permission_profile_id'], $authority)->id;
                }
                $roots = $data['scope_roots'] ?? $member->scopeRoots();
                $descendants = $data['include_descendants'] ?? $member->include_descendants;
                $authority->scope($actor, $account, $roots, $descendants);
                $member->include_descendants = $descendants;
                if (array_key_exists('notes', $data)) {
                    $member->notes = $data['notes'];
                }
                $user = $member->user()->lockForUpdate()->firstOrFail();
                $user->fill(array_intersect_key($data, array_flip(['name', 'email'])))->save();
                $member->version++;
                $member->save();
                $this->roots($member, $roots);
                $revoker->revoke([$user->id]);
                $audit->record('staff.update', $request, $actor, $id, ['membership_id' => $member->id, 'reason' => $data['reason'], 'version' => $member->version, 'fields' => array_keys(array_diff_key($data, ['reason' => true]))]);

                return $member;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'بيانات المستخدم مستخدمة مسبقاً.']);
        }

        return response()->json(['data' => $member->fresh()->resource()]);
    }

    public function status(StaffRequest $request, int $id, int $memberId, ManagementAuthority $authority, AuditLogger $audit, SessionRevoker $revoker): JsonResponse
    {
        $member = DB::transaction(function () use ($request, $id, $memberId, $authority, $audit, $revoker) {
            $actor = $request->user();
            $authority->account($actor, $id, 'staff.toggle', true, true);
            $member = AccountMembership::where('account_id', $id)->lockForUpdate()->findOrFail($memberId);
            $authority->member($actor, $member);
            $data = $request->validated();
            $authority->version($member, $data['version']);
            if ($data['status'] === 'active') {
                $authority->grants($actor, $member->profile->permissions());
                abort_unless($member->profile->status === 'active', 422, 'نوع الصلاحية موقوف.');
            }
            $member->status = $data['status'];
            $member->version++;
            $member->save();
            $revoker->revoke([$member->user_id]);
            $audit->record('staff.status', $request, $actor, $id, ['membership_id' => $member->id, 'status' => $member->status, 'reason' => $data['reason'], 'version' => $member->version]);

            return $member;
        });

        return response()->json(['data' => $member->fresh()->resource()]);
    }

    private function profile(User $actor, Account $account, int $id, ManagementAuthority $authority): PermissionProfile
    {
        $profile = PermissionProfile::where('account_id', $account->id)->lockForUpdate()->findOrFail($id);
        if ($profile->status !== 'active') {
            throw ValidationException::withMessages(['permission_profile_id' => 'اختر نوع صلاحية فعالاً.']);
        }
        $authority->grants($actor, $profile->permissions());
        $owner = AccountMembership::where('account_id', $account->id)->where('kind', 'owner')->firstOrFail();
        if (array_diff($profile->permissions(), $owner->permissions())) {
            throw ValidationException::withMessages(['permission_profile_id' => 'نوع الصلاحية يتجاوز صلاحيات الحساب.']);
        }

        return $profile;
    }

    private function roots(AccountMembership $member, array $roots): void
    {
        DB::table('membership_scope_roots')->where('membership_id', $member->id)->delete();
        foreach ($roots as $root) {
            DB::table('membership_scope_roots')->insert(['membership_id' => $member->id, 'account_id' => $root]);
        }
    }
}
