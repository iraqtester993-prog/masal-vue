<?php

namespace App\Services\Support;

use App\Models\Support\SupportOperation;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use Illuminate\Support\Facades\DB;

class SupportOperations
{
    public function execute(User $actor, string $action, array $payload, callable $operation): array
    {
        $fingerprint = hash('sha256', json_encode([$action, $payload], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $action, $payload, $operation, $fingerprint): array {
            $guard = app(MutationGuard::class);
            $guard->lock($actor, $guard->targets($payload), $action);
            $record = SupportOperation::where('user_id', $actor->id)->where('key', $payload['idempotency_key'])->lockForUpdate()->first();
            if ($record) {
                abort_unless(hash_equals($record->fingerprint, $fingerprint), 409, 'مفتاح العملية مستخدم لطلب مختلف.');

                return $record->result;
            }
            $record = SupportOperation::create(['user_id' => $actor->id, 'key' => $payload['idempotency_key'], 'action' => $action, 'fingerprint' => $fingerprint]);
            $result = $operation();
            $record->update(['result' => $result]);

            return $result;
        }, 3);
    }
}
