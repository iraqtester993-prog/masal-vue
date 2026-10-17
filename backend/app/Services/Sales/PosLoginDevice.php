<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosLoginDevice
{
    public function required(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'يلزم التحقق من جهاز النقطة؛ افتح التطبيق وسجل الدخول من جهازك المرتبط.', 'code' => 'POS_LOGIN_PROOF_REQUIRED'], 428));
    }

    public function mismatch(): never
    {
        throw new HttpResponseException(response()->json(['message' => 'هذه النقطة مرتبطة بجهاز آخر. اطلب من الوكيل أو مدير النظام نقل الدخول إلى هذا الجهاز.', 'code' => 'POS_LOGIN_DEVICE_MISMATCH'], 423));
    }

    /** The caller holds the POS account lock before claiming its first device. */
    public function acceptHeartbeat(int $accountId, ?object $device, ?string $proofHash, Request $request): bool
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        DB::table('pos_login_bindings')->insertOrIgnore(['account_id' => $accountId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $binding = DB::table('pos_login_bindings')->where('account_id', $accountId)->lockForUpdate()->first();
        if (! $device || ! $proofHash || ! empty($device->archived_at) || empty($device->proof_public_key)) {
            return false;
        }
        if ($binding->registered_device_id === null) {
            DB::table('pos_login_bindings')->where('account_id', $accountId)->update(['registered_device_id' => $device->id, 'version' => $binding->version + 1, 'updated_at' => now()]);
            $binding->registered_device_id = $device->id;
            $binding->version++;
        }
        if ((int) $binding->registered_device_id !== (int) $device->id || ! hash_equals(hash('sha256', $device->proof_public_key), $proofHash)) {
            return false;
        }
        $request->session()->put(['masal.pos_login_device_id' => (int) $device->id,
            'masal.pos_login_key_hash' => $proofHash, 'masal.pos_login_binding_version' => (int) $binding->version]);

        return true;
    }

    public function assertCurrent(User $actor, Request $request, bool $required = true): void
    {
        if ($actor->membership->account->type !== AccountType::Pos) {
            return;
        }
        if (! $required && ! $request->hasSession()) {
            return;
        }
        $query = DB::table('pos_login_bindings')->where('account_id', $actor->membership->account_id);
        $binding = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->first();
        if (! $binding || $binding->registered_device_id === null) {
            if ($required) {
                $this->required();
            }

            return;
        }
        if (! $request->hasSession() || ! $request->session()->has('masal.pos_login_device_id')) {
            $this->required();
        }
        if ((int) $binding->registered_device_id !== (int) $request->session()->get('masal.pos_login_device_id')
            || (int) $binding->version !== (int) $request->session()->get('masal.pos_login_binding_version')) {
            $this->mismatch();
        }
        $query = DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->where('account_id', $actor->membership->account_id);
        $device = (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->first();
        if (! $device || ! empty($device->archived_at) || empty($device->proof_public_key)
            || ! hash_equals(hash('sha256', $device->proof_public_key), (string) $request->session()->get('masal.pos_login_key_hash', ''))) {
            $this->mismatch();
        }
    }
}
