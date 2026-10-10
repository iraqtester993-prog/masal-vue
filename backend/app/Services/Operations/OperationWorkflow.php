<?php

namespace App\Services\Operations;

use App\Models\Account;
use App\Models\AccountAttachment;
use App\Models\AccountMembership;
use App\Models\Operations\AccountArchive;
use App\Models\Operations\AccountTimePolicy;
use App\Models\Operations\DirectStop;
use App\Models\Operations\OperationMutation;
use App\Models\Operations\OperationStop;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\SessionRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class OperationWorkflow
{
    public function __construct(private OperationAccess $access, private AccountScope $scope, private AuditLogger $audit, private SessionRevoker $revoker, private ArchiveBlockers $blockers) {}

    private function execute(User $actor, string $action, array $data, callable $callback): array
    {
        unset($data['password']);
        $hash = hash('sha256', json_encode([$action, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $action, $data, $callback, $hash): array {
            app(MutationGuard::class)->lockRuntime();
            $version = (int) ($actor->session_version ?? 1);
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $user->session_version === $version, 401);
            abort_unless($user->isOperational(), 403);
            $actor->unsetRelation('membership');
            $actor->refresh();
            $record = OperationMutation::where('user_id', $actor->id)->where('key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($record) {
                abort_unless(hash_equals($record->fingerprint, $hash), 409, 'مفتاح العملية مستخدم لطلب مختلف.');

                return $record->result;
            }
            $record = OperationMutation::create(['user_id' => $actor->id, 'key' => $data['idempotency_key'], 'action' => $action, 'fingerprint' => $hash, 'created_at' => now()]);
            $result = $callback();
            $record->update(['result' => $result]);

            return $result;
        }, 3);
    }

    public function createStop(User $actor, array $data, Request $request): array
    {
        $this->access->requireSecurity($actor, true);

        return $this->execute($actor, 'security.stop', $data, function () use ($actor, $data, $request): array {
            $this->access->requireSecurity($actor, true);
            $ids = $data['account_ids'] ?? [];
            if ($ids) {
                abort_unless($this->access->accounts($actor)->whereIn('id', $ids)->count() === count($ids), 404);
            }
            $roots = $actor->membership->kind === 'owner' ? null : $actor->membership->scopeRoots();
            abort_if($roots === [], 403, 'لا يوجد نطاق مفوض.');
            $stop = OperationStop::create(['creator_id' => $actor->id, 'authority_account_id' => $actor->membership->account_id, 'scope' => $data['scope'], 'actions' => $data['actions'], 'scope_roots' => $roots, 'include_descendants' => $actor->membership->include_descendants, 'reason' => trim($data['reason'])]);
            foreach (['operation_stop_targets' => $ids, 'operation_stop_scope_roots' => $roots ?? []] as $table => $accounts) {
                foreach (array_chunk($accounts, 500) as $chunk) {
                    DB::table($table)->insert(array_map(fn ($id) => ['stop_id' => $stop->id, 'account_id' => $id], $chunk));
                }
            }
            $this->audit->record('security.stop', $request, $actor, null, ['stop_id' => $stop->id, 'scope' => $stop->scope, 'actions' => $stop->actions]);

            return ['id' => $stop->id];
        });
    }

    public function resume(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->requireSecurity($actor, true);
        $this->access->stops($actor)->findOrFail($id);

        return $this->execute($actor, 'security.resume:'.$id, $data, function () use ($actor, $id, $data, $request): array {
            $this->access->requireSecurity($actor, true);
            $stop = $this->access->stops($actor)->lockForUpdate()->findOrFail($id);
            abort_unless($stop->active && $stop->version === (int) $data['version'], 409, 'تغير قرار التوقيف؛ أعد فتحه.');
            $stop->update(['active' => false, 'version' => $stop->version + 1, 'resumed_by' => $actor->id, 'resumed_at' => now()]);
            $this->audit->record('security.resume', $request, $actor, null, ['stop_id' => $id]);

            return ['id' => $id];
        });
    }

    public function direct(User $actor, int $id, array $data, Request $request, bool $global = false): array
    {
        $this->access->requireSecurity($actor, true);
        if ($global) {
            abort_unless($this->access->canRestoreGlobal($actor), 403, 'إلغاء التوقيف العام يتطلب صلاحياته ونطاق النظام كاملًا.');
        }
        if (! $global) {
            $this->access->accounts($actor)->findOrFail($id);
        }

        return $this->execute($actor, 'security.direct:'.$id, $data, function () use ($actor, $id, $data, $request, $global): array {
            $this->access->requireSecurity($actor, true);
            if ($global) {
                abort_unless($this->access->canRestoreGlobal($actor), 403);
            }
            $account = $global ? $actor->membership->account : $this->access->accounts($actor)->lockForUpdate()->findOrFail($id);
            $record = DirectStop::where('account_id', $account->id)->lockForUpdate()->first();
            abort_unless(($record?->version ?? 0) === (int) $data['version'], 409, 'تغيرت ضوابط الحساب؛ أعد فتحها.');
            $stops = [];
            foreach ($global ? OperationGuard::KEYS : ['login', 'sales', 'printing', 'import'] as $name) {
                $stops[$name] = $global ? false : (bool) ($data['stops'][$name] ?? false);
            }
            $record = DirectStop::updateOrCreate(['account_id' => $id], ['stops' => $stops, 'version' => ($record?->version ?? 0) + 1]);
            $this->audit->record('security.direct', $request, $actor, $id, ['stops' => $stops, 'version' => $record->version]);

            return ['account_id' => $id];
        });
    }

    public function timeUser(User $actor, int $id, bool $lock = false): User
    {
        $this->access->requireSecurity($actor, false, true);
        $query = User::whereIn('id', AccountMembership::where('kind', 'employee')->whereIn('account_id', $this->scope->query($actor)->select('id'))->select('user_id'));

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
    }

    public function saveTime(User $actor, int $id, array $data, Request $request): array
    {
        $this->timeUser($actor, $id);

        return $this->execute($actor, 'security.time:'.$id, $data, function () use ($actor, $id, $data, $request): array {
            $this->timeUser($actor, $id, true);
            $record = AccountTimePolicy::where('user_id', $id)->lockForUpdate()->first();
            abort_unless(($record?->version ?? 0) === (int) $data['version'], 409, 'تغيرت ضوابط الوقت؛ أعد فتحها.');
            $policy = array_merge(AccountTimeGuard::emptyPolicy(), $data['policy']);
            foreach (['enabled', 'dateEnabled', 'hoursEnabled', 'idleEnabled', 'sessionEnabled'] as $flag) {
                $policy[$flag] = (bool) $policy[$flag];
            }
            foreach (['startAt', 'endAt'] as $field) {
                $policy[$field] = (string) ($policy[$field] ?? '');
            }
            foreach (['startTime' => '08:00', 'endTime' => '16:00'] as $field => $default) {
                $policy[$field] = $policy[$field] ?: $default;
            }
            foreach (['idleMinutes', 'sessionMinutes'] as $field) {
                $policy[$field] = (int) $policy[$field];
            }
            $record = AccountTimePolicy::updateOrCreate(['user_id' => $id], ['policy' => $policy, 'version' => ($record?->version ?? 0) + 1]);
            $this->revoker->revoke([$id]);
            $this->audit->record('security.account_time', $request, $actor, null, ['user_id' => $id, 'policy' => $policy, 'version' => $record->version]);

            return ['user_id' => $id];
        });
    }

    public function archive(User $actor, int $id, array $data, Request $request): array
    {
        $this->access->archiveTarget($actor, $id);
        $rate = 'account-archive-password:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rate, 5), 429, 'محاولات كثيرة؛ حاول بعد خمس دقائق.');
        $credential = User::findOrFail($actor->id)->password;
        if (! Hash::check($data['password'], $credential)) {
            RateLimiter::hit($rate, 300);
            throw ValidationException::withMessages(['password' => 'كلمة المرور غير صحيحة.']);
        }
        $result = $this->execute($actor, 'account.archive:'.$id, $data, function () use ($actor, $id, $data, $request, $credential): array {
            $this->access->archiveTarget($actor, $id);
            abort_unless(hash_equals(User::findOrFail($actor->id)->password, $credential), 409, 'تغير الحساب؛ أعد التأكيد.');
            $ancestors = DB::table('account_closure')->where('descendant_id', $id)->pluck('ancestor_id');
            Account::whereIn('id', $ancestors)->orderBy('id')->lockForUpdate()->get();
            $account = $this->access->archiveTarget($actor, $id, true);
            abort_if($account->archived_at || $account->version !== (int) $data['version'], 409, 'تغير الحساب؛ أعد التأكيد.');
            $reasons = $this->blockers->reasons($account, true);
            if ($reasons) {
                throw ValidationException::withMessages(['account' => $reasons]);
            }
            $members = AccountMembership::where('account_id', $id)->with('user')->orderBy('user_id')->lockForUpdate()->get();
            abort_if($members->contains('user_id', $actor->id), 403, 'لا يمكن أرشفة حسابك الحالي.');
            if ($actor->membership->kind === 'employee') {
                $visible = $this->scope->query($actor, true)->pluck('id')->all();
                foreach ($members->where('kind', 'employee') as $member) {
                    $expanded = $member->include_descendants ? DB::table('account_closure')->whereIn('ancestor_id', $member->scopeRoots())->pluck('descendant_id')->all() : $member->scopeRoots();
                    abort_if(array_diff($expanded, $visible), 403, 'لا تملك نطاق جميع مستخدمي هذا الحساب.');
                }
            }
            $before = $account->only(['id', 'name', 'type', 'status', 'parent_id', 'city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_model', 'app_version', 'notes', 'version']);
            $before['parent_name'] = $account->parent?->name;
            $before['image_id'] = AccountAttachment::where('account_id', $id)->where('kind', $account->type->value === 'pos' ? 'personal_image' : 'agent_image')->orderByDesc('id')->value('id');
            $users = $members->map(fn ($m) => ['id' => $m->user_id, 'membership_id' => $m->id, 'name' => $m->user->name, 'email' => $m->user->email, 'login' => $m->user->login, 'kind' => $m->kind, 'user_status' => $m->user->status, 'membership_status' => $m->status])->all();
            $entry = AccountArchive::create(['account_id' => $id, 'actor_id' => $actor->id, 'reason' => trim($data['reason']), 'before' => $before, 'users' => $users, 'created_at' => now()]);
            $account->forceFill(['status' => 'disabled', 'archived_at' => now(), 'version' => $account->version + 1])->save();
            foreach ($members as $member) {
                $member->update(['status' => 'disabled', 'version' => $member->version + 1]);
                $member->user->update(['status' => 'disabled']);
            }
            $this->revoker->revoke($members->pluck('user_id')->all());
            $this->audit->record('account.archive', $request, $actor, $id, ['archive_id' => $entry->id, 'reason' => $entry->reason, 'version' => $account->version]);

            return ['id' => $entry->id, 'account_id' => $id, 'version' => $account->version, 'archived_at' => $account->archived_at];
        });
        RateLimiter::clear($rate);

        return $result;
    }
}
