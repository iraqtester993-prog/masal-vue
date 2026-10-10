<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\LoginRequest;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Maps\MapAccess;
use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuditLogger $audit): JsonResponse
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

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('masal.portal', $portal);
        $request->session()->put('masal.session_version', $user->session_version);
        app(AccountTimeGuard::class)->start($user, $request);
        $audit->record('auth.login', $request, $user);

        return response()->json(['data' => $this->identity($user, $portal)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->identity($request->user(), $request->attributes->get('portal'))]);
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
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120']]);
        foreach (array_diff(array_keys($request->except('_token')), ['version', 'name']) as $field) {
            throw ValidationException::withMessages([$field => 'هذا الحقل غير مسموح.']);
        }
        $user = DB::transaction(function () use ($request, $audit, $data) {
            app(MutationGuard::class)->lock($request->user());
            $member = AccountMembership::where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->kind === 'owner', 403);
            abort_unless($member->version === (int) $data['version'], 409, 'تغيرت البيانات؛ أعد فتحها قبل الحفظ.');
            $user = $member->user()->lockForUpdate()->firstOrFail();
            $user->name = $data['name'];
            $user->save();
            $member->version++;
            $member->save();
            $audit->record('auth.profile', $request, $user, $member->account_id, ['version' => $member->version, 'fields' => ['name']]);

            return $user->fresh();
        });

        return response()->json(['data' => $this->identity($user, $request->attributes->get('portal'))]);
    }

    private function identity(User $user, string $portal): array
    {
        $account = $user->membership->account;

        return [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'login' => $user->login],
            'account' => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type->value, 'parent_id' => $account->parent_id, 'status' => $account->status, 'device_lock_enabled' => $account->device_lock_enabled],
            'portal' => $portal,
            'permissions' => $user->membership->permissions(),
            'membership' => ['kind' => $user->membership->kind, 'version' => $user->membership->version, 'scope_roots' => $user->membership->scopeRoots(), 'include_descendants' => $user->membership->include_descendants],
        ];
    }
}
