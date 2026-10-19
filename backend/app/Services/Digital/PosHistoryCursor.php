<?php

namespace App\Services\Digital;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosHistoryCursor
{
    public function decode(Request $request, int $accountId): ?array
    {
        if (! $request->filled('cursor')) {
            return null;
        }
        try {
            [$body, $signature] = explode('.', $request->input('cursor'), 2);
            if (! hash_equals(hash_hmac('sha256', $body, (string) config('app.key')), $signature)) {
                throw new \UnexpectedValueException;
            }
            $payload = json_decode(base64_decode(strtr($body, '-_', '+/'), true), true, 16, JSON_THROW_ON_ERROR);
            if (($payload['v'] ?? null) !== 1 || ! is_string($payload['scope'] ?? null) || ! hash_equals($this->scope($request, $accountId), $payload['scope'])
                || ! is_int($payload['id'] ?? null) || $payload['id'] < 1 || ! in_array($payload['type'] ?? null, ['stock', 'digital'], true)
                || ! is_string($payload['time'] ?? null) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?\z/D', $payload['time'])) {
                throw new \UnexpectedValueException;
            }

            return $payload;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'مؤشر السجل غير صالح أو تغير الحساب أو الفلتر. حدّث السجل من البداية.']);
        }
    }

    public function encode(Request $request, int $accountId, object $row): string
    {
        $payload = ['v' => 1, 'scope' => $this->scope($request, $accountId), 'time' => $row->occurred_at, 'id' => (int) $row->operation_id, 'type' => $row->operation_type];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, (string) config('app.key'));
    }

    private function scope(Request $request, int $accountId): string
    {
        $actor = $request->user();
        $permissions = $actor->membership->permissions();
        sort($permissions, SORT_STRING);

        return hash('sha256', json_encode([$actor->id, $actor->session_version, $accountId, $permissions,
            $request->input('kind') ?: 'all', (string) $request->input('q', ''), (string) $request->input('from', ''), (string) $request->input('to', ''), $request->integer('per_page', 10)], JSON_THROW_ON_ERROR));
    }
}
