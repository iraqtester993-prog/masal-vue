<?php

namespace App\Http\Controllers;

use App\Services\PhoneRecovery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class PhoneRecoveryController extends Controller
{
    public function issue(Request $request, PhoneRecovery $recovery): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);

        return response()->json(['data' => $recovery->issue($request, $data['phone'])]);
    }

    public function reset(Request $request, PhoneRecovery $recovery): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/D'],
            'password' => ['required', 'string', Password::min(9), 'max:128', 'confirmed'],
        ]);
        $recovery->reset($request, $data);

        return response()->json(['message' => 'تم تغيير كلمة المرور. سجل الدخول بكلمة المرور الجديدة.']);
    }
}
