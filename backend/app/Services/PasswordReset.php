<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use App\Services\Sales\DeviceProof;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordReset
{
    public function seed(Request $request, User $user): void
    {
        $current = $this->boundRequest($request);
        if ($current && $current->trusted && (int) $current->user_id === $user->id) {
            return;
        }
        $this->createBinding($request, $user, true);
    }

    public function request(Request $request, string $login): array
    {
        $identifier = mb_strtolower(trim($login));
        $user = User::where(str_contains($identifier, '@') ? 'email' : 'login', $identifier)->first();
        if (! $user || ! $user->isOperational() || $user->membership->account->type->portal() !== $request->attributes->get('portal')) {
            $user = null;
        }

        return $this->createBinding($request, $user, false);
    }

    public function requestStatus(Request $request): array
    {
        $record = $this->boundRequest($request);
        if (! $record) {
            return ['status' => 'none'];
        }
        if ($record->consumed_at !== null) {
            return ['status' => 'completed'];
        }
        if ($record->approved_at !== null && Carbon::parse($record->approval_expires_at)->isPast()) {
            return ['status' => 'expired'];
        }
        $user = $record->user_id ? User::find($record->user_id) : null;
        $approved = $record->approved_at !== null && $user && $user->password_change_required_at !== null
            && (int) $record->reset_version === (int) $user->password_reset_version;

        return ['status' => $approved ? 'approved' : 'pending', 'request_label' => $this->label($record->id), 'trusted' => (bool) $record->trusted]
            + ($approved ? ['version' => (int) $record->reset_version] : []);
    }

    public function pendingRequests(User $actor, Account $target): array
    {
        if (! $this->canReset($actor, $target)) {
            return [];
        }
        $owner = AccountMembership::where('account_id', $target->id)->where('kind', 'owner')->first();
        if (! $owner) {
            return [];
        }

        return $this->eligibleRequests($owner->user)->orderByDesc('last_seen_at')->limit(20)->get()
            ->filter(fn ($row): bool => $this->deviceBindingValid($row))->map(fn ($row): array => ['id' => $row->id, 'request_label' => $this->label($row->id),
                'trusted' => (bool) $row->trusted, 'requested_at' => Carbon::parse($row->created_at)->toISOString(), 'last_seen_at' => Carbon::parse($row->last_seen_at)->toISOString(),
                'expires_at' => Carbon::parse($row->expires_at)->toISOString(), 'device_label' => $row->device_label])->values()->all();
    }

    public function deviceChallenge(Request $request, array $data): array
    {
        $user = $this->provedDeviceUser($request, $data);
        if ($user) {
            return app(DeviceProof::class)->challenge($user, $user->membership->account, $data, $request);
        }

        return ['challenge_id' => (string) Str::uuid(), 'challenge' => json_encode(['purpose' => 'dananeer-device-proof-v1', 'nonce' => bin2hex(random_bytes(32))], JSON_THROW_ON_ERROR), 'expires_in' => 120];
    }

    public function deviceBind(Request $request, array $data): array
    {
        $user = $this->provedDeviceUser($request, $data);
        abort_unless($user, 422, 'تعذر اعتماد جهاز الاستعادة.');
        app(DeviceProof::class)->verify($user, $user->membership->account, $data, $request);

        return DB::transaction(function () use ($request, $data, $user): array {
            app(MutationGuard::class)->lockRuntime();
            $account = Account::whereKey($user->membership->account_id)->lockForUpdate()->firstOrFail();
            $fresh = $this->provedDeviceUser($request, $data);
            abort_unless($fresh && $fresh->id === $user->id && $fresh->session_version === $user->session_version, 422, 'تعذر اعتماد جهاز الاستعادة.');
            $fresh = User::whereKey($fresh->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->session_version === $user->session_version && $fresh->isOperational(), 422, 'تعذر اعتماد جهاز الاستعادة.');
            $result = $this->createBinding($request, $fresh, true);
            $binding = DB::table('pos_login_bindings')->where('account_id', $account->id)->first();
            $intent = DB::table('managed_password_reset_requests')->where('user_id', $fresh->id)->where('awaiting_device_proof', true)
                ->where('registered_device_id', $binding->registered_device_id)->where('binding_version', $binding->version)
                ->where('session_version', $fresh->session_version)->where('reset_version', $fresh->password_reset_version)
                ->whereNull('consumed_at')->where('approval_expires_at', '>', now())->lockForUpdate()->first();
            if ($intent && $fresh->password_change_required_at !== null) {
                DB::table('managed_password_reset_requests')->where('id', $intent->id)->update(['consumed_at' => now()]);
                DB::table('managed_password_reset_requests')->where('id', $result['request_id'])->update(['approved_at' => $intent->approved_at,
                    'approval_expires_at' => $intent->approval_expires_at, 'approved_by' => $intent->approved_by, 'reset_version' => $intent->reset_version]);
                $result['status'] = 'approved';
                $result['version'] = (int) $intent->reset_version;
            }

            return $result;
        });
    }

    public function state(User $user): array
    {
        return ['required' => $user->password_change_required_at !== null, 'version' => (int) $user->password_reset_version];
    }

    public function assertReady(User $user): void
    {
        if ($user->password_change_required_at !== null) {
            throw new HttpResponseException(response()->json(['message' => 'يلزم تعيين كلمة مرور جديدة قبل متابعة استخدام الحساب.', 'code' => 'PASSWORD_CHANGE_REQUIRED', 'password_reset' => $this->state($user)], 403));
        }
    }

    public function allowsRequiredRequest(Request $request): bool
    {
        return ($request->isMethod('GET') && in_array($request->path(), ['api/v1/auth/me', 'api/v1/auth/password-reset/status'], true))
            || ($request->isMethod('POST') && in_array($request->path(), ['api/v1/auth/password-reset/complete', 'api/v1/device/challenge', 'api/v1/device/heartbeat'], true));
    }

    public function canReset(User $actor, Account $target, bool $knownVisible = false): bool
    {
        $member = $actor->membership;
        if (! $actor->isOperational() || $member->kind !== 'owner' || $member->account->type === AccountType::Pos
            || ! in_array('account.password_reset', $member->permissions(), true) || $target->archived_at !== null
            || $target->type === AccountType::System || $target->id === $member->account_id) {
            return false;
        }

        return $knownVisible || app(AccountScope::class)->query($actor)->whereKey($target->id)->exists();
    }

    public function issue(Request $request, int $id, array $data): Account
    {
        return DB::transaction(function () use ($request, $id, $data): Account {
            $actor = $request->user();
            $authority = app(ManagementAuthority::class);
            $target = $authority->account($actor, $id, 'account.password_reset', false, true);
            abort_unless($this->canReset($actor, $target, true), 403);
            $authority->version($target, (int) $data['version']);
            $member = AccountMembership::where('account_id', $id)->where('kind', 'owner')->lockForUpdate()->firstOrFail();
            $user = $member->user()->lockForUpdate()->firstOrFail();
            abort_unless($user->isOperational(), 409, 'الحساب المطلوب غير متاح.');
            $this->assertNoPendingWork($id);
            $claim = null;
            if (! empty($data['request_id'])) {
                $claim = $this->eligibleRequests($user)->where('id', $data['request_id'])->lockForUpdate()->first();
                abort_unless($claim && $this->deviceBindingValid($claim), 409, 'طلب الاستعادة غير متاح؛ أعد فتح تفاصيل الحساب.');
            } else {
                $trusted = $this->eligibleRequests($user)->where('trusted', true)->lockForUpdate()->get()->filter(fn ($row): bool => $this->deviceBindingValid($row));
                abort_if($trusted->count() > 1, 409, 'يوجد أكثر من جهاز موثوق؛ اختر طلب الجهاز المقصود قبل التأكيد.');
                $claim = $trusted->first();
            }
            $user->forceFill(['password_change_required_at' => now(), 'password_reset_version' => $user->password_reset_version + 1])->save();
            DB::table('managed_password_reset_requests')->where('user_id', $user->id)->whereNotNull('approved_at')->whereNull('consumed_at')
                ->when($claim, fn ($query) => $query->where('id', '<>', $claim->id))->update(['consumed_at' => now()]);
            if ($claim) {
                DB::table('managed_password_reset_requests')->where('id', $claim->id)->update(['approved_at' => now(), 'approval_expires_at' => now()->addMinutes(15), 'approved_by' => $actor->id, 'reset_version' => $user->password_reset_version]);
            } elseif ($target->type === AccountType::Pos) {
                $binding = DB::table('pos_login_bindings')->where('account_id', $id)->first();
                $device = $binding ? DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->where('account_id', $id)->first() : null;
                if ($binding && $device && $device->status === 'approved' && empty($device->archived_at) && ! empty($device->proof_public_key)) {
                    DB::table('managed_password_reset_requests')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'account_id' => $id,
                        'portal' => 'pos', 'nonce_hash' => hash('sha256', random_bytes(32)), 'session_version' => $user->session_version,
                        'trusted' => true, 'awaiting_device_proof' => true, 'device_label' => 'جهاز نقطة البيع المرتبط', 'registered_device_id' => $device->id,
                        'binding_version' => $binding->version, 'created_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addMinutes(15),
                        'approved_at' => now(), 'approval_expires_at' => now()->addMinutes(15), 'approved_by' => $actor->id, 'reset_version' => $user->password_reset_version]);
                }
            }
            $target->increment('version');
            app(AuditLogger::class)->record('account.password_reset', $request, $actor, $id, ['reason' => $data['reason'], 'subject_user_id' => $user->id,
                'reset_version' => $user->password_reset_version, 'version' => $target->version, 'request_id' => $claim?->id]);

            return $target;
        });
    }

    public function complete(Request $request, array $data): User
    {
        return DB::transaction(function () use ($request, $data): User {
            $user = $request->user();
            app(MutationGuard::class)->lock($user, [], 'auth.password_reset');

            return $this->replacePassword($request, $user, $data);
        });
    }

    public function completeRequest(Request $request, array $data): void
    {
        DB::transaction(function () use ($request, $data): void {
            $candidate = $this->boundRequest($request);
            abort_unless($candidate && $candidate->user_id, 422, 'طلب الاستعادة غير صالح أو انتهت صلاحيته.');
            $user = User::findOrFail($candidate->user_id);
            $guard = app(MutationGuard::class);
            $guard->lockRuntime();
            $accountId = $user->membership->account_id;
            $guard->lockAccounts(DB::table('account_closure')->where('descendant_id', $accountId)->pluck('ancestor_id')->all(), $accountId);
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isOperational(), 422, 'طلب الاستعادة غير صالح أو انتهت صلاحيته.');
            app(OperationGuard::class)->assertAllowedCurrent($accountId, 'app');
            $record = $this->boundRequest($request, true);
            abort_unless($record && $record->approved_at !== null && $record->consumed_at === null
                && Carbon::parse($record->approval_expires_at)->isFuture() && (int) $record->reset_version === (int) $data['version'], 422, 'طلب الاستعادة غير صالح أو انتهت صلاحيته.');
            $this->replacePassword($request, $user, $data, ['request_id' => $record->id, 'approved_by' => $record->approved_by]);
        });
    }

    private function replacePassword(Request $request, User $user, array $data, array $auditContext = []): User
    {
        abort_unless($user->password_change_required_at !== null && (int) $data['version'] === (int) $user->password_reset_version, 409, 'تغير طلب إعادة التعيين؛ أعد تحميل الصفحة.');
        $this->assertNoPendingWork($user->membership->account_id);
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'اختر كلمة مرور جديدة تختلف عن الحالية.']);
        }
        $user->forceFill(['password' => $data['password'], 'password_change_required_at' => null, 'password_reset_version' => $user->password_reset_version + 1, 'remember_token' => null])->save();
        app(SessionRevoker::class)->revoke([$user->id]);
        DB::table('managed_password_reset_requests')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        app(AuditLogger::class)->record('auth.password_reset', $request, $user, $user->membership->account_id, ['reset_version' => $user->password_reset_version] + $auditContext);

        return $user->fresh();
    }

    private function createBinding(Request $request, ?User $user, bool $trusted): array
    {
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $expires = $trusted ? now()->addDays(30) : now()->addMinutes(15);
        $binding = $trusted && $user?->membership->account->type === AccountType::Pos
            ? DB::table('pos_login_bindings')->where('account_id', $user->membership->account_id)->first() : null;
        $device = $binding ? DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->first() : null;
        $agent = (string) $request->userAgent();
        $label = $device?->model ?: (str_contains($agent, 'iPad') ? 'جهاز لوحي' : (str_contains($agent, 'iPhone') ? 'آيفون' : (str_contains($agent, 'Android') ? 'هاتف أندرويد' : 'متصفح '.($trusted ? 'موثوق' : 'جهاز جديد'))));
        DB::transaction(function () use ($request, $id, $secret, $expires, $trusted, $user, $binding, $label): void {
            app(MutationGuard::class)->lockRuntime();
            $expired = DB::table('managed_password_reset_requests')->where('expires_at', '<', now())
                ->where(fn ($query) => $query->whereNull('approval_expires_at')->orWhere('approval_expires_at', '<', now()))
                ->orderBy('expires_at')->limit(100)->pluck('id');
            if ($expired->isNotEmpty()) {
                DB::table('managed_password_reset_requests')->whereIn('id', $expired)->delete();
            }
            $old = $this->boundRequest($request);
            if ($old) {
                DB::table('managed_password_reset_requests')->where('id', $old->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            }
            DB::table('managed_password_reset_requests')->insert(['id' => $id, 'user_id' => $user?->id, 'account_id' => $user?->membership->account_id,
                'portal' => $request->attributes->get('portal'), 'nonce_hash' => hash('sha256', $secret), 'session_version' => $user?->session_version,
                'trusted' => $trusted, 'device_label' => mb_substr($label, 0, 120), 'registered_device_id' => $binding?->registered_device_id,
                'binding_version' => $binding?->version, 'expires_at' => $expires, 'created_at' => now(), 'last_seen_at' => now()]);
        });
        Cookie::queue(cookie($this->cookieName($request), $id.'.'.$secret, $trusted ? 43200 : 15, '/', null, true, true, false, 'lax'));

        return ['request_id' => $id, 'request_label' => $this->label($id), 'status' => 'pending', 'expires_at' => $expires->toISOString(), 'trusted' => $trusted];
    }

    private function eligibleRequests(User $user): Builder
    {
        return DB::table('managed_password_reset_requests')->where('user_id', $user->id)->where('portal', $user->membership->account->type->portal())
            ->where('session_version', $user->session_version)->where('awaiting_device_proof', false)->whereNull('consumed_at')->where('expires_at', '>', now());
    }

    private function boundRequest(Request $request, bool $lock = false): ?object
    {
        $cookie = $request->cookie($this->cookieName($request));
        if (! is_string($cookie) || ! preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/D', $cookie, $parts)) {
            return null;
        }
        $query = DB::table('managed_password_reset_requests')->where('id', $parts[1])->where('portal', $request->attributes->get('portal'));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row || ! hash_equals($row->nonce_hash, hash('sha256', $parts[2])) || Carbon::parse($row->expires_at)->isPast() || ! $this->deviceBindingValid($row)) {
            return null;
        }
        if ($row->user_id) {
            $user = User::find($row->user_id);
            if (! $user || ! $user->isOperational() || (int) $row->session_version !== (int) $user->session_version) {
                return null;
            }
        }

        return $row;
    }

    private function deviceBindingValid(object $row): bool
    {
        if (! $row->trusted || $row->portal !== 'pos') {
            return true;
        }
        $binding = DB::table('pos_login_bindings')->where('account_id', $row->account_id)->first();
        $device = $binding ? DB::table('pos_registered_devices')->where('id', $binding->registered_device_id)->where('account_id', $row->account_id)->first() : null;

        return $binding && $device && (int) $binding->registered_device_id === (int) $row->registered_device_id && (int) $binding->version === (int) $row->binding_version
            && $device->status === 'approved' && empty($device->archived_at) && ! empty($device->proof_public_key);
    }

    private function provedDeviceUser(Request $request, array $data): ?User
    {
        if ($request->attributes->get('portal') !== 'pos') {
            return null;
        }
        $device = DB::table('pos_registered_devices')->join('pos_login_bindings', 'pos_login_bindings.registered_device_id', '=', 'pos_registered_devices.id')
            ->whereColumn('pos_login_bindings.account_id', 'pos_registered_devices.account_id')->where('pos_registered_devices.identifier_hash', hash('sha256', strtolower($data['installation_id'])))
            ->where('pos_registered_devices.proof_public_key', $data['proof_public_key'])->where('pos_registered_devices.proof_key_kind', $data['proof_key_kind'])
            ->where('pos_registered_devices.status', 'approved')->whereNull('pos_registered_devices.archived_at')->first(['pos_registered_devices.account_id']);
        $user = $device ? AccountMembership::where('account_id', $device->account_id)->where('kind', 'owner')->first()?->user : null;

        return $user && $user->isOperational() ? $user : null;
    }

    private function cookieName(Request $request): string
    {
        return 'masal_'.$request->attributes->get('portal').'_password_recovery';
    }

    private function label(string $id): string
    {
        return strtoupper(substr(str_replace('-', '', $id), -6));
    }

    private function assertNoPendingWork(int $accountId): void
    {
        $sale = DB::table('sales')->where('account_id', $accountId)->where(fn ($query) => $query->where('print_pending', true)->orWhere('status', 'Reserved'))->exists();
        $digital = DB::table('digital_orders')->where('account_id', $accountId)->where(fn ($query) => $query->where('reservation_active', true)->orWhereIn('status', ['pending', 'review'])->orWhereNull('acknowledged_at'))->exists();
        $attempts = DB::table('digital_provider_attempts as a')->join('digital_orders as o', 'o.id', '=', 'a.order_id')->where('o.account_id', $accountId);
        $active = (clone $attempts)->whereNotNull('a.active_order_id')->exists();
        $refund = (clone $attempts)->where('a.operation', 'refund-status')->where('a.status', 'unknown')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('digital_provider_attempts as later')->whereColumn('later.order_id', 'a.order_id')
                ->where('later.operation', 'refund-status')->whereColumn('later.id', '>', 'a.id'))->exists();
        abort_if($sale || $digital || $active || $refund, 409, 'أكمل نتيجة البيع أو الطباعة المعلّقة قبل إعادة تعيين كلمة المرور.');
    }
}
