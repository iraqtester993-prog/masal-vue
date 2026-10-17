<?php

namespace App\Services\Sales;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Sales\DeviceSession;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegisteredDevices
{
    public static function rules(): array
    {
        return DeviceProof::rules() + [
            'installation_id' => ['sometimes', 'uuid'],
            'kind' => ['sometimes', 'in:pos,mobile,browser'],
            'model' => ['sometimes', 'nullable', 'string', 'max:190'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:100'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:190'],
            'app_version' => ['required', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'],
            'os_version' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'],
        ];
    }

    public static function dto(object $row): array
    {
        return [
            'id' => $row->id, 'device_id' => 'DN-'.strtoupper(substr($row->identifier_hash, 0, 12)),
            'kind' => $row->kind, 'model' => $row->model, 'manufacturer' => $row->manufacturer,
            'serial' => $row->serial, 'os_version' => $row->os_version, 'app_version' => $row->app_version,
            'status' => $row->status, 'version' => (int) $row->version, 'last_seen_at' => CarbonImmutable::parse($row->last_seen_at)->toISOString(),
            'proof_enrolled' => ! empty($row->proof_public_key), 'archived' => ! empty($row->archived_at),
            'reviewed_at' => $row->reviewed_at ? CarbonImmutable::parse($row->reviewed_at)->toISOString() : null,
        ];
    }

    public static function sessionProofValid(object $registered, DeviceSession $session): bool
    {
        return ($registered->status ?? '') === 'approved' && empty($registered->archived_at) && ! empty($registered->proof_public_key)
            && ! empty($session->proof_key_hash) && $session->proof_verified_at
            && CarbonImmutable::parse($session->proof_verified_at)->greaterThan(now()->subSeconds(90))
            && hash_equals(hash('sha256', $registered->proof_public_key), $session->proof_key_hash);
    }

    public function heartbeat(User $actor, Account $own, array $data, Request $request, bool $rejectLoginMismatch = true): array
    {
        abort_unless($own->type === AccountType::Pos, 403);

        $proofHash = app(DeviceProof::class)->verify($actor, $own, $data, $request);

        $result = DB::transaction(function () use ($actor, $own, $data, $request, $proofHash): array {
            app(MutationGuard::class)->lockRuntime();
            $own = Account::whereKey($own->id)->lockForUpdate()->firstOrFail();
            $fresh = User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless((int) $fresh->session_version === (int) $actor->session_version && $fresh->isOperational(), 401, 'انتهت صلاحية الجلسة؛ سجل الدخول مجددًا.');
            $registered = null;
            if (! empty($data['installation_id'])) {
                $hash = hash('sha256', strtolower($data['installation_id']));
                $query = DB::table('pos_registered_devices')->where('account_id', $own->id)->where('identifier_hash', $hash);
                if (! (clone $query)->exists()) {
                    abort_if(DB::table('pos_registered_devices')->where('account_id', $own->id)->whereNull('archived_at')->whereIn('status', ['pending', 'approved'])->count() >= 30, 429, 'بلغ عدد أجهزة النقطة الحد المسموح؛ راجع الوكيل.');
                    DB::table('pos_registered_devices')->insertOrIgnore([
                        'account_id' => $own->id, 'identifier_hash' => $hash, 'kind' => $data['kind'] ?? 'browser',
                        'app_version' => $data['app_version'], 'status' => 'pending', 'version' => 1,
                        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $registered = (clone $query)->lockForUpdate()->first();
                abort_if(! empty($registered->archived_at), 403, 'هذا الجهاز مؤرشف؛ سجل جهازًا جديدًا أو راجع الوكيل.');
                if ($proofHash !== null) {
                    abort_if(! empty($registered->proof_public_key) && ! hash_equals($registered->proof_public_key, $data['proof_public_key']), 403, 'مفتاح الجهاز لا يطابق الاعتماد المسجل.');
                    if (empty($registered->proof_public_key)) {
                        (clone $query)->update(['proof_public_key' => $data['proof_public_key'], 'proof_key_kind' => $data['proof_key_kind'],
                            'proof_enrolled_at' => now(), 'status' => $registered->status === 'approved' ? 'pending' : $registered->status,
                            'version' => $registered->version + 1, 'reviewed_by' => null, 'reviewed_at' => null]);
                    }
                }
                if ($proofHash !== null || $registered->status !== 'approved') {
                    (clone $query)->update([
                        'kind' => $data['kind'] ?? 'browser', 'model' => $data['model'] ?? null,
                        'manufacturer' => $data['manufacturer'] ?? null, 'serial' => $data['serial'] ?? null,
                        'os_version' => $data['os_version'] ?? null, 'app_version' => $data['app_version'],
                        'last_seen_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $registered = (clone $query)->first();
            }
            $loginAllowed = app(PosLoginDevice::class)->acceptHeartbeat($own->id, $registered, $proofHash, $request);
            $device = DeviceSession::updateOrCreate(['session_hash' => hash('sha256', $request->session()->getId())], [
                'account_id' => $own->id, 'user_id' => $actor->id, 'session_version' => $actor->session_version,
                'registered_device_id' => $registered?->id, 'proof_key_hash' => $proofHash, 'proof_verified_at' => $proofHash ? now() : null, 'serial' => $data['serial'] ?? null,
                'app_version' => $data['app_version'], 'os_version' => $data['os_version'] ?? null,
                'last_seen_at' => now(), 'expires_at' => now()->addSeconds(90),
            ]);
            $allowed = ! $own->device_lock_enabled || ($registered ? $registered->status === 'approved' && self::sessionProofValid($registered, $device) : (! $own->device_binding_enabled && (! $own->serial || $own->serial === $device->serial)));

            return ['online' => true, 'allowed' => $allowed, 'login_allowed' => $loginAllowed, 'device_lock_enabled' => $own->device_lock_enabled, 'proof_required' => $own->device_lock_enabled && $proofHash === null,
                'expires_at' => $device->expires_at->toISOString(), 'app_version' => $device->app_version,
                'os_version' => $device->os_version, 'device' => $registered ? self::dto($registered) : null];
        });
        if (! $result['login_allowed'] && $proofHash !== null && $rejectLoginMismatch) {
            $request->session()->forget(['masal.pos_login_device_id', 'masal.pos_login_key_hash', 'masal.pos_login_binding_version']);
            app(PosLoginDevice::class)->mismatch();
        }

        return $result;
    }
}
