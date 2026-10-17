<?php

namespace App\Http\Controllers;

use App\Http\Resources\AccountResource;
use App\Services\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PasswordResetController extends Controller
{
    public function request(Request $request, PasswordReset $resets): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:190']]);

        return response()->json(['data' => $resets->request($request, $data['login'])], 202);
    }

    public function requestStatus(Request $request, PasswordReset $resets): JsonResponse
    {
        return response()->json(['data' => $resets->requestStatus($request)]);
    }

    public function deviceChallenge(Request $request, PasswordReset $resets): JsonResponse
    {
        return response()->json(['data' => $resets->deviceChallenge($request, $this->device($request))]);
    }

    public function deviceBind(Request $request, PasswordReset $resets): JsonResponse
    {
        return response()->json(['data' => $resets->deviceBind($request, $this->device($request, true))]);
    }

    public function status(Request $request, PasswordReset $resets): JsonResponse
    {
        return response()->json(['data' => $resets->state($request->user())]);
    }

    public function issue(Request $request, int $id, PasswordReset $resets): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:500'], 'request_id' => ['sometimes', 'nullable', 'uuid']]);

        return response()->json(['data' => ['account' => (new AccountResource($resets->issue($request, $id, $data)))->resolve($request)]]);
    }

    public function complete(Request $request, PasswordReset $resets, AuthController $auth): JsonResponse
    {
        $oldSession = hash('sha256', $request->session()->getId());
        $user = $resets->complete($request, $this->password($request));
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();
        $request->session()->put('masal.session_version', $user->session_version);
        DB::table('sales_device_sessions')->where('session_hash', $oldSession)->where('user_id', $user->id)->update(['session_hash' => hash('sha256', $request->session()->getId()), 'session_version' => $user->session_version]);
        $resets->seed($request, $user);

        return response()->json(['data' => $auth->identity($user, $request->attributes->get('portal'))]);
    }

    public function completeRequest(Request $request, PasswordReset $resets): JsonResponse
    {
        $resets->completeRequest($request, $this->password($request));

        return response()->json(['data' => ['completed' => true]]);
    }

    private function device(Request $request, bool $signed = false): array
    {
        return $request->validate(['installation_id' => ['required', 'uuid'], 'proof_public_key' => ['required', 'string', 'max:512'],
            'proof_key_kind' => ['required', 'in:android-keystore,webcrypto']]
            + ($signed ? ['proof_challenge_id' => ['required', 'uuid'], 'proof_signature' => ['required', 'string', 'max:256']] : []));
    }

    private function password(Request $request): array
    {
        return $request->validate(['version' => ['required', 'integer', 'min:1'], 'password' => ['required', 'string', 'min:9', 'max:72', 'confirmed', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && strlen($value) > 72) {
                $fail('كلمة المرور يجب ألا تتجاوز 72 بايت.');
            }
        }], 'password_confirmation' => ['required', 'string']], ['password.min' => 'كلمة المرور يجب ألا تقل عن 9 خانات.', 'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.']);
    }
}
