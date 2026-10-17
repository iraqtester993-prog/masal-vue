<?php

namespace App\Services\Sales;

use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeviceProof
{
    public static function rules(): array
    {
        return ['proof_public_key' => ['sometimes', 'string', 'max:512'],
            'proof_key_kind' => ['sometimes', 'in:android-keystore,webcrypto'],
            'proof_challenge_id' => ['sometimes', 'uuid'], 'proof_signature' => ['sometimes', 'string', 'max:256']];
    }

    private function publicKey(string $encoded): \OpenSSLAsymmetricKey
    {
        $der = base64_decode($encoded, true);
        abort_unless($der !== false && strlen($der) >= 80 && strlen($der) <= 180, 422, 'مفتاح الجهاز غير صالح.');
        $key = @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n");
        $details = $key ? openssl_pkey_get_details($key) : null;
        abort_unless($key && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === 'prime256v1', 422, 'مفتاح الجهاز غير مدعوم.');

        return $key;
    }

    public function challenge(User $actor, Account $account, array $data, Request $request): array
    {
        $this->publicKey($data['proof_public_key']);
        $id = (string) Str::uuid();
        $message = json_encode(['purpose' => 'dananeer-device-proof-v1', 'nonce' => bin2hex(random_bytes(32)), 'challenge_id' => $id,
            'account_id' => $account->id, 'installation_id' => strtolower($data['installation_id']),
            'key_hash' => hash('sha256', $data['proof_public_key'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Cache::put('device-proof:'.$id, ['message' => $message, 'user_id' => $actor->id, 'session_version' => $actor->session_version,
            'session_hash' => hash('sha256', $request->session()->getId()), 'account_id' => $account->id,
            'installation_id' => strtolower($data['installation_id']), 'key_hash' => hash('sha256', $data['proof_public_key'])], 120);

        return ['challenge_id' => $id, 'challenge' => $message, 'expires_in' => 120];
    }

    public function verify(User $actor, Account $account, array $data, Request $request): ?string
    {
        if (! isset($data['proof_public_key'], $data['proof_key_kind'], $data['proof_challenge_id'], $data['proof_signature'])) {
            abort_if(array_intersect(array_keys($data), array_keys(self::rules())) !== [], 422, 'إثبات الجهاز غير مكتمل.');

            return null;
        }
        $key = $this->publicKey($data['proof_public_key']);
        $signature = base64_decode($data['proof_signature'], true);
        abort_unless($signature !== false && strlen($signature) >= 64 && strlen($signature) <= 80, 422, 'توقيع الجهاز غير صالح.');
        $lock = Cache::lock('device-proof-use:'.$data['proof_challenge_id'], 10);
        abort_unless($lock->get(), 422, 'إثبات الجهاز مستخدم.');
        try {
            $challenge = Cache::pull('device-proof:'.$data['proof_challenge_id']);
            abort_unless($challenge && (int) $challenge['user_id'] === (int) $actor->id
                && (int) $challenge['session_version'] === (int) $actor->session_version
                && (int) $challenge['account_id'] === (int) $account->id
                && hash_equals($challenge['session_hash'], hash('sha256', $request->session()->getId()))
                && hash_equals($challenge['installation_id'], strtolower($data['installation_id']))
                && hash_equals($challenge['key_hash'], hash('sha256', $data['proof_public_key']))
                && openssl_verify($challenge['message'], $signature, $key, OPENSSL_ALGO_SHA256) === 1, 422, 'انتهى إثبات الجهاز أو لا يطابق هذه الجلسة.');

            return $challenge['key_hash'];
        } finally {
            $lock->release();
        }
    }
}
