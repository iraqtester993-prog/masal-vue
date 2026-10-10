<?php

namespace App\Services\Operations;

use App\Models\Operations\AccountTimePolicy;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AccountTimeGuard
{
    public static function emptyPolicy(): array
    {
        return ['enabled' => false, 'dateEnabled' => false, 'startAt' => '', 'endAt' => '', 'hoursEnabled' => false, 'startTime' => '08:00', 'endTime' => '16:00', 'idleEnabled' => false, 'idleMinutes' => 60, 'sessionEnabled' => false, 'sessionMinutes' => 60];
    }

    public function reason(User $actor, ?array $clock = null): ?string
    {
        if ($actor->status !== 'active') {
            return 'الحساب موقوف.';
        }
        if ($actor->membership?->kind !== 'employee') {
            return null;
        }
        $policy = AccountTimePolicy::find($actor->id)?->policy;
        if (! ($policy['enabled'] ?? false)) {
            return null;
        }
        $now = CarbonImmutable::now('Asia/Baghdad');
        if ($policy['dateEnabled']) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $policy['startAt'], 'Asia/Baghdad');
            $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $policy['endAt'], 'Asia/Baghdad');
            if ($now->lt($start) || $now->gte($end)) {
                return 'الدخول خارج مدة صلاحية الحساب.';
            }
        }
        if ($policy['hoursEnabled']) {
            $minute = $now->hour * 60 + $now->minute;
            $start = $this->minutes($policy['startTime']);
            $end = $this->minutes($policy['endTime']);
            if (! ($start < $end ? $minute >= $start && $minute < $end : $minute >= $start || $minute < $end)) {
                return 'الدخول خارج ساعات الدوام — توقيت بغداد.';
            }
        }
        if ($clock) {
            if ($policy['sessionEnabled'] && $now->timestamp - (int) $clock['started'] >= $policy['sessionMinutes'] * 60) {
                return 'انتهت مدة الجلسة؛ سجّل الدخول مجددًا.';
            }
            if ($policy['idleEnabled'] && $now->timestamp - (int) $clock['lastActivity'] >= $policy['idleMinutes'] * 60) {
                return 'تم تسجيل الخروج بسبب الخمول.';
            }
        }

        return null;
    }

    private function minutes(string $value): int
    {
        return (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);
    }

    public function assertLogin(User $actor): void
    {
        if ($reason = $this->reason($actor)) {
            throw ValidationException::withMessages(['login' => $reason]);
        }
    }

    public function start(User $actor, Request $request): void
    {
        $request->session()->put('masal.time_clock', ['user' => $actor->id, 'started' => now()->timestamp, 'lastActivity' => now()->timestamp]);
    }

    public function enforce(User $actor, Request $request, bool $activity = false): void
    {
        $clock = $request->session()->get('masal.time_clock');
        $policy = $actor->membership?->kind === 'employee' ? AccountTimePolicy::find($actor->id)?->policy : null;
        $missing = ($policy['enabled'] ?? false) && (($policy['idleEnabled'] ?? false) || ($policy['sessionEnabled'] ?? false)) && (! is_array($clock) || ($clock['user'] ?? null) !== $actor->id || ! isset($clock['started'],$clock['lastActivity']));
        $reason = $missing ? 'سجّل الدخول لبدء جلسة جديدة.' : $this->reason($actor, is_array($clock) && ($clock['user'] ?? null) === $actor->id ? $clock : null);
        if ($reason) {
            app(AuditLogger::class)->record('auth.time_expired', $request, $actor, null, ['reason' => $reason]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401, $reason);
        }
        if ($activity) {
            if (! is_array($clock) || ($clock['user'] ?? null) !== $actor->id) {
                $this->start($actor, $request);
            } else {
                $clock['lastActivity'] = now()->timestamp;
                $request->session()->put('masal.time_clock', $clock);
            }
        }
    }
}
