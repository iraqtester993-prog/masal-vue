<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\LoginRequest;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AccountPrivacy;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Maps\MapAccess;
use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use App\Services\PasswordReset;
use App\Services\Sales\DeviceProof;
use App\Services\Sales\PosLoginDevice;
use App\Services\Sales\RegisteredDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    private function credentials(LoginRequest $request, AuditLogger $audit): User
    {
        $validated = $request->validated();
        $login = mb_strtolower(trim($validated['login']));
        $user = User::where(str_contains($login, '@') ? 'email' : 'login', $login)->first();
        $portal = $request->attributes->get('portal');
        $hash = $user?->password ?? config('auth.dummy_password_hash');
        $validPassword = Hash::check($validated['password'], $hash);

        if (! $user || ! $validPassword || ! $user->isOperational() || $user->membership->account->type->portal() !== $portal) {
            $audit->record('auth.login_failed', $request);
            throw ValidationException::withMessages(['login' => 'بيانات الدخول غير صحيحة أو الحساب غير متاح لهذه البوابة.']);
        }

        app(AccountTimeGuard::class)->assertLogin($user);
        if (! ($user->membership->kind === 'owner' && $user->membership->account->type === AccountType::System)) {
            app(OperationGuard::class)->assertAllowed($user->membership->account_id, 'login');
        }

        return $user;
    }

    public function deviceChallenge(LoginRequest $request, AuditLogger $audit, DeviceProof $proof): JsonResponse
    {
        $user = $this->credentials($request, $audit);
        abort_unless($user->membership->account->type === AccountType::Pos, 403);
        $data = $request->validate(['installation_id' => ['required', 'uuid'], 'proof_public_key' => ['required', 'string', 'max:512'], 'proof_key_kind' => ['required', 'in:android-keystore,webcrypto']]);

        return response()->json(['data' => $proof->challenge($user, $user->membership->account, $data, $request)]);
    }

    public function login(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = $this->credentials($request, $audit);
        $validated = $request->validated();
        $portal = $request->attributes->get('portal');
        $oldSessionHash = hash('sha256', $request->session()->getId());
        if ($user->membership->account->type === AccountType::Pos) {
            foreach (['installation_id', 'app_version', 'proof_public_key', 'proof_key_kind', 'proof_challenge_id', 'proof_signature'] as $field) {
                if (empty($validated[$field])) {
                    app(PosLoginDevice::class)->required();
                }
            }
            $result = DB::transaction(function () use ($user, $validated, $request): array {
                $guard = app(MutationGuard::class);
                $guard->lockRuntime();
                $own = $user->membership->account;
                $guard->lockAccounts(DB::table('account_closure')->where('descendant_id', $own->id)->pluck('ancestor_id')->all(), $own->id, shareAncestors: true);
                $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $fresh->session_version === (int) $user->session_version && $fresh->isOperational(), 401);
                app(AccountTimeGuard::class)->assertLogin($fresh);
                app(OperationGuard::class)->assertAllowedCurrent($own->id, 'login');

                return app(RegisteredDevices::class)->heartbeat($fresh, $own, $validated, $request, rejectLoginMismatch: false);
            });
            if (! $result['login_allowed']) {
                $request->session()->forget(['masal.pos_login_device_id', 'masal.pos_login_key_hash', 'masal.pos_login_binding_version']);
                $audit->record('pos.device.login_rejected', $request, $user, $user->membership->account_id);
                app(PosLoginDevice::class)->mismatch();
            }
        }
        Auth::guard('web')->login($user, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();
        $request->session()->put('masal.portal', $portal);
        $request->session()->put('masal.session_version', $user->session_version);
        if ($portal === 'pos') {
            DB::table('sales_device_sessions')->where('session_hash', $oldSessionHash)->where('user_id', $user->id)->update(['session_hash' => hash('sha256', $request->session()->getId())]);
        }
        app(AccountTimeGuard::class)->start($user, $request);
        $audit->record('auth.login', $request, $user);
        app(PasswordReset::class)->seed($request, $user);

        return response()->json(['data' => $this->identity($user, $portal)]);
    }

    public function me(Request $request): JsonResponse
    {
        app(PasswordReset::class)->seed($request, $request->user());

        return response()->json(['data' => $this->identity($request->user(), $request->attributes->get('portal'))]);
    }

    private function brandAccount(User $user): ?Account
    {
        if ($user->membership->account->type !== AccountType::Pos) {
            return null;
        }

        return Account::whereIn('id', DB::table('account_closure')->where('descendant_id', $user->membership->account_id)->select('ancestor_id'))
            ->where('type', AccountType::MainAgent)->first();
    }

    public function brandImage(Request $request): Response
    {
        $brand = $this->brandAccount($request->user());
        abort_unless($brand && $brand->isOperational(), 404);
        $image = AccountAttachment::where('account_id', $brand->id)->where('kind', 'agent_image')->latest('id')->firstOrFail();
        abort_unless(in_array($image->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true) && Storage::disk('local')->exists($image->storage_path), 404);

        return response()->file(Storage::disk('local')->path($image->storage_path), ['Content-Type' => $image->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function personalImage(Request $request): Response
    {
        $account = $request->user()->membership->account;
        $image = AccountAttachment::where('account_id', $account->id)->where('kind', 'personal_image')->latest('id')->firstOrFail();
        abort_unless(in_array($image->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true) && Storage::disk('local')->exists($image->storage_path), 404);

        return response()->file(Storage::disk('local')->path($image->storage_path), ['Content-Type' => $image->mime_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function logout(Request $request, AuditLogger $audit): Response
    {
        $audit->record('auth.logout', $request, $request->user());
        app(MapAccess::class)->disconnect($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function profile(Request $request, AuditLogger $audit): JsonResponse
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'], 'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($request->user()->id)], 'color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'], 'current_password' => ['required_with:email,password', 'string', 'current_password:web'], 'password' => ['sometimes', 'string', 'min:9', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && strlen($value) > 72) {
                $fail('كلمة المرور يجب ألا تتجاوز 72 بايت.');
            }
        }], 'password_confirmation' => ['required_with:password', 'string']], ['current_password.required_with' => 'أدخل كلمة المرور الحالية لتعديل البريد أو كلمة المرور.', 'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.', 'email.unique' => 'البريد الإلكتروني مستخدم مسبقًا.', 'password.min' => 'كلمة المرور يجب ألا تقل عن 9 خانات.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.', 'color.regex' => 'اختر لونًا صحيحًا.']);
        foreach (array_diff(array_keys($request->except('_token')), ['version', 'name', 'email', 'color', 'current_password', 'password', 'password_confirmation']) as $field) {
            throw ValidationException::withMessages([$field => 'هذا الحقل غير مسموح.']);
        }
        $user = DB::transaction(function () use ($request, $audit, $data) {
            app(MutationGuard::class)->lock($request->user());
            $member = AccountMembership::where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version === (int) $data['version'], 409, 'تغيرت البيانات؛ أعد فتحها قبل الحفظ.');
            $user = $member->user()->lockForUpdate()->firstOrFail();
            $user->name = $data['name'];
            if (isset($data['email'])) {
                $user->email = mb_strtolower(trim($data['email']));
                $user->email_verified_at = null;
            }
            if (isset($data['password'])) {
                $user->password = Hash::make($data['password']);
                $user->session_version++;
            }
            if (isset($data['color'])) {
                abort_unless($member->kind === 'owner', 403);
                abort_unless(in_array($member->account->type, [AccountType::MainAgent, AccountType::SubAgent, AccountType::SubBranch], true), 403);
                $account = Account::whereKey($member->account_id)->lockForUpdate()->firstOrFail();
                $account->update(['color' => strtoupper($data['color']), 'version' => $account->version + 1]);
            }
            $user->save();
            $member->version++;
            $member->save();
            $audit->record('auth.profile', $request, $user, $member->account_id, ['version' => $member->version, 'fields' => array_values(array_intersect(['name', 'email', 'password', 'color'], array_keys($data)))]);

            return $user->fresh();
        });

        if (isset($data['password'])) {
            $request->session()->regenerate();
            $request->session()->put('masal.session_version', $user->session_version);
        }

        return response()->json(['data' => $this->identity($user, $request->attributes->get('portal'))]);
    }

    public function identity(User $user, string $portal): array
    {
        $account = $user->membership->account;
        $brand = $this->brandAccount($user);
        $brandImage = $brand ? AccountAttachment::where('account_id', $brand->id)->where('kind', 'agent_image')->latest('id')->value('id') : null;
        $personalImage = AccountAttachment::where('account_id', $account->id)->where('kind', 'personal_image')->latest('id')->value('id');
        $privacy = app(AccountPrivacy::class);
        $ownerName = $account->type === AccountType::System ? null : ($account->owner_name ?: DB::table('account_memberships')->joinSub($privacy->users(), 'users', 'users.id', '=', 'account_memberships.user_id')
            ->where('account_memberships.account_id', $account->id)->where('account_memberships.kind', 'owner')->value('users.name'));
        $posTypeName = $account->type === AccountType::Pos
            ? DB::table('pos_reference_profiles')->join('pos_types', 'pos_types.id', '=', 'pos_reference_profiles.pos_type_id')
                ->where('pos_reference_profiles.account_id', $account->id)->value('pos_types.name')
            : null;
        $systemScope = app(ManagementAuthority::class)->hasSystemScope($user);
        $permissions = $user->membership->permissions();
        $backupAccess = array_intersect(['backup.view', 'backup.create', 'backup.restore'], $permissions) && app(ManagementAuthority::class)->hasFullSystemScope($user);
        $managementAccount = array_filter($permissions, fn (string $permission): bool => str_starts_with($permission, 'staff.') || str_starts_with($permission, 'permission_profile.'))
            ? app(AccountScope::class)->query($user)->orderByRaw('CASE WHEN accounts.id = ? THEN 0 ELSE 1 END', [$account->id])->orderBy('accounts.id')->first(['id', 'name', 'type', 'status']) : null;

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'login' => $user->login],
            'account' => ['id' => $account->id, 'name' => $privacy->accountName($account), 'owner_name' => $ownerName, 'type' => $account->type->value, 'color' => $account->color, 'pos_type_name' => $posTypeName, 'parent_id' => $account->parent_id, 'parent_name' => $privacy->accountName($account->parent), 'personal_image_url' => $personalImage ? '/api/v1/auth/personal-image?v='.$personalImage : null, 'status' => $account->status, 'device_lock_enabled' => $account->device_lock_enabled],
            'portal' => $portal,
            'password_reset' => app(PasswordReset::class)->state($user),
            'brand' => $brand ? ['name' => $privacy->accountName($brand), 'color' => preg_match('/^#[0-9a-f]{6}$/i', $brand->color ?? '') ? $brand->color : '#0D5C63', 'logo_url' => $brandImage ? '/api/v1/auth/brand-image?v='.$brandImage : null] : null,
            'permissions' => $permissions,
            'capabilities' => ['system_scope' => $systemScope, 'app_settings' => $systemScope && in_array('app.settings', $permissions, true), 'printers_manage' => $systemScope && in_array('printers.manage', $permissions, true), 'backup_access' => $backupAccess, 'management_account_id' => $managementAccount?->id, 'management_account' => $managementAccount ? ['id' => $managementAccount->id, 'name' => $privacy->accountName($managementAccount), 'type' => $managementAccount->type->value, 'status' => $managementAccount->status] : null],
            'membership' => ['kind' => $user->membership->kind, 'version' => $user->membership->version, 'scope_roots' => $user->membership->scopeRoots(), 'include_descendants' => $user->membership->include_descendants],
        ];
    }
}
