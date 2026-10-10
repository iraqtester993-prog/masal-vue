<?php

namespace App\Services;

use App\Models\User;
use App\Services\Notifications\ActivityNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    public function record(string $action, Request $request, ?User $user = null, ?int $subjectAccountId = null, array $details = []): void
    {
        DB::transaction(function () use ($action, $request, $user, $subjectAccountId, $details): void {
            $auditId = DB::table('audit_logs')->insertGetId([
                'user_id' => $user?->id,
                'account_id' => $user?->membership?->account_id,
                'subject_account_id' => $subjectAccountId,
                'action' => $action,
                'portal' => $request->attributes->get('portal'),
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'details' => $details ? json_encode($details, JSON_THROW_ON_ERROR) : null,
            ]);
            if (class_exists(ActivityNotifications::class) && Schema::hasTable('notices')) {
                app(ActivityNotifications::class)->publish((int) $auditId);
            }
        });
    }
}
