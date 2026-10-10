<?php

namespace App\Services;

use App\Models\User;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhoneRecovery
{
    public function normalize(string $phone): string
    {
        $phone = strtr($phone, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $phone = preg_replace('/[\s()+-]/u', '', $phone);
        $phone = preg_replace('/^(?:00964|964|0)/', '', $phone);
        if (! preg_match('/^7\d{9}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'أدخل رقم هاتف عراقي صحيحًا.']);
        }

        return '964'.$phone;
    }

    private function available(): void
    {
        abort_unless(app()->environment(['local', 'testing']) && config('auth.phone_recovery_test_mode'), 503, 'خدمة استعادة كلمة المرور عبر الهاتف لم تُفعّل بعد.');
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function owner(string $phone, string $portal): ?User
    {
        $number = substr($phone, 3);
        $expression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(accounts.phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', '')";
        $users = User::whereHas('membership', function ($query) use ($expression, $number, $phone): void {
            $query->where('kind', 'owner')->whereHas('account', fn ($account) => $account->whereIn(DB::raw($expression), [$phone, '00'.$phone, '0'.$number]));
        })->with('membership.account')->limit(2)->get();
        $user = $users->count() === 1 ? $users->first() : null;

        return $user && $user->isOperational() && $user->membership->account->type->portal() === $portal ? $user : null;
    }

    public function issue(Request $request, string $input): array
    {
        $this->available();
        $phone = $this->normalize($input);
        $limit = 'phone-recovery:'.$this->digest($phone);
        abort_if(RateLimiter::tooManyAttempts($limit, 3), 429);
        RateLimiter::hit($limit, 300);
        $portal = $request->attributes->get('portal');
        $user = $this->owner($phone, $portal);
        $challenge = Str::random(64);
        Cache::put('phone-recovery:'.$this->digest($challenge), [
            'user_id' => $user?->id, 'version' => $user?->session_version,
            'phone' => $phone, 'portal' => $portal,
            'session' => $this->digest($request->session()->getId()),
            'attempts' => 0, 'expires' => now()->addMinutes(5)->timestamp,
        ], 300);

        return ['challenge' => $challenge, 'expires_in' => 300, 'test_mode' => true,
            'message' => 'إذا كان الرقم مرتبطًا بحساب متاح، يمكنك إكمال التحقق.'];
    }

    public function reset(Request $request, array $data): void
    {
        $this->available();
        $key = 'phone-recovery:'.$this->digest($data['challenge']);
        Cache::lock($key.':lock', 15)->block(3, function () use ($request, $data, $key): void {
            $state = Cache::get($key);
            $valid = $state && $state['expires'] > now()->timestamp && $state['attempts'] < 5
                && $state['portal'] === $request->attributes->get('portal')
                && hash_equals($state['session'], $this->digest($request->session()->getId()));
            if (! $valid) {
                $this->invalid();
            }
            $state['attempts']++;
            Cache::put($key, $state, max(1, $state['expires'] - now()->timestamp));
            if (! hash_equals('123456', $data['code']) || ! $state['user_id']) {
                $this->invalid();
            }
            DB::transaction(function () use ($request, $data, $state): void {
                $user = User::find($state['user_id']);
                if (! $user || (int) $user->session_version !== (int) $state['version']) {
                    $this->invalid();
                }
                app(MutationGuard::class)->lock($user, [], 'auth.password_reset');
                if ($this->owner($state['phone'], $state['portal'])?->id !== $user->id) {
                    $this->invalid();
                }
                $user->forceFill(['password' => $data['password'], 'session_version' => $user->session_version + 1, 'remember_token' => null])->save();
                $user->membership->increment('version');
                app(AuditLogger::class)->record('auth.password_reset', $request, $user, $user->membership->account_id);
            });
            Cache::forget($key);
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['code' => 'رمز التحقق غير صحيح أو انتهت صلاحيته. اطلب رمزًا جديدًا عند الحاجة.']);
    }
}
