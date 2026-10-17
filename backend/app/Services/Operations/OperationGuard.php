<?php

namespace App\Services\Operations;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Operations\DirectStop;
use App\Models\Operations\OperationStop;
use App\Support\RequestReadCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationGuard
{
    public const KEYS = ['app', 'login', 'sales', 'printing', 'import'];

    public function __construct(private OperationAccess $access) {}

    /** Compute the table's effective restrictions with bounded, bulk queries. */
    public function restrictions(Collection $accounts): array
    {
        $result = [];
        $ancestors = [];
        $parents = [];
        $closure = DB::table('account_closure')->join('accounts', 'accounts.id', '=', 'ancestor_id')
            ->whereIn('descendant_id', $accounts->pluck('id'))->get(['ancestor_id', 'descendant_id', 'depth', 'accounts.parent_id', 'accounts.status', 'accounts.archived_at']);
        $direct = DirectStop::whereIn('account_id', $closure->pluck('ancestor_id')->unique())->get()->keyBy('account_id');
        foreach ($accounts as $account) {
            $result[$account->id] = [];
        }
        foreach ($closure as $row) {
            $ancestors[$row->descendant_id][$row->ancestor_id] = (int) $row->depth;
            $parents[$row->ancestor_id] = $row->parent_id;
            if ($row->status !== 'active' || $row->archived_at !== null) {
                $result[$row->descendant_id] = self::KEYS;
            }
            foreach ($direct->get($row->ancestor_id)?->stops ?? [] as $key => $blocked) {
                if ($blocked && in_array($key, self::KEYS, true)) {
                    $result[$row->descendant_id] = array_merge($result[$row->descendant_id], $key === 'app' ? self::KEYS : [$key]);
                }
            }
        }
        $rules = OperationStop::where('active', true)->get();
        $targets = DB::table('operation_stop_targets')->whereIn('stop_id', $rules->pluck('id'))->get()->groupBy('stop_id')
            ->map(fn ($rows) => array_fill_keys($rows->pluck('account_id')->all(), true));
        $byType = $rules->groupBy('scope');
        foreach ($accounts as $account) {
            $next = $account->id;
            $depth = 0;
            $seen = [];
            while ($next !== null) {
                if (isset($seen[$next]) || ! isset($ancestors[$account->id][$next]) || $ancestors[$account->id][$next] !== $depth) {
                    $result[$account->id] = self::KEYS;
                    break;
                }
                $seen[$next] = true;
                $next = $parents[$next];
                $depth++;
            }
            if (count($seen) !== count($ancestors[$account->id] ?? [])) {
                $result[$account->id] = self::KEYS;
            }
            $type = array_search($account->type->value, OperationAccess::TYPES, true);
            foreach ($byType->get('all', collect())->concat($byType->get($type, collect()))->concat($byType->get('custom', collect())) as $rule) {
                if ($rule->scope === 'custom' && ! isset(($targets[$rule->id] ?? [])[$account->id])) {
                    continue;
                }
                if ($rule->scope_roots !== null) {
                    $matched = false;
                    foreach ($rule->scope_roots as $root) {
                        if (isset($ancestors[$account->id][$root]) && ($rule->include_descendants || $ancestors[$account->id][$root] === 0)) {
                            $matched = true;
                            break;
                        }
                    }
                    if (! $matched) {
                        continue;
                    }
                }
                $result[$account->id] = array_merge($result[$account->id], in_array('app', $rule->actions, true) ? self::KEYS : $rule->actions);
            }
            $result[$account->id] = array_values(array_unique($result[$account->id]));
        }

        return $result;
    }

    public function reason(int $accountId, string $key): ?string
    {
        abort_unless(in_array($key, self::KEYS, true), 500);

        return RequestReadCache::remember('OperationGuard.reason:'.$accountId.':'.$key, fn (): ?string => $this->resolveReason($accountId, $key));
    }

    private function context(int $accountId): array
    {
        return RequestReadCache::remember('OperationGuard.context:'.$accountId, function () use ($accountId): array {
            $account = Account::findOrFail($accountId);
            if (! $account->isOperational()) {
                return ['blocked' => 'الحساب أو الجهة الأعلى موقوفة.'];
            }
            $ancestors = DB::table('account_closure')->where('descendant_id', $accountId)->pluck('ancestor_id');
            if (Account::whereIn('id', $ancestors)->whereNotNull('archived_at')->exists()) {
                return ['blocked' => 'الحساب مؤرشف ولا يقبل عمليات جديدة.'];
            }
            $type = array_search($account->type->value, OperationAccess::TYPES, true);
            $rules = OperationStop::where('active', true)->where(function ($q) use ($type, $accountId): void {
                $q->where('scope', 'all')->orWhere('scope', $type ?: 'none')->orWhere(function ($custom) use ($accountId): void {
                    $custom->where('scope', 'custom')->whereIn('id', DB::table('operation_stop_targets')->where('account_id', $accountId)->select('stop_id'));
                });
            })->orderByDesc('id')->get();

            return ['blocked' => null, 'rules' => $rules, 'direct' => DirectStop::whereIn('account_id', $ancestors)->get()];
        });
    }

    private function resolveReason(int $accountId, string $key): ?string
    {
        $context = $this->context($accountId);
        if ($context['blocked']) {
            return $context['blocked'];
        }
        foreach ($context['rules'] as $rule) {
            if ((in_array('app', $rule->actions, true) || in_array($key, $rule->actions, true)) && RequestReadCache::remember('OperationGuard.match:'.$rule->id.':'.$accountId, fn (): bool => $this->access->matchedAccounts($rule)->whereKey($accountId)->exists())) {
                return 'موقوف: '.$rule->reason;
            }
        }
        foreach ($context['direct'] as $direct) {
            if (($direct->stops['app'] ?? false) || ($direct->stops[$key] ?? false)) {
                return 'العملية موقوفة لهذه الجهة أو الجهة الأعلى.';
            }
        }

        return null;
    }

    public function assertAllowed(int $accountId, string $key): void
    {
        if ($reason = $this->reason($accountId, $key)) {
            abort(423, $reason);
        }
    }

    /** Recheck admission after the actor's account lineage has been locked. */
    public function assertAllowedCurrent(int $accountId, string $key): void
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($key, self::KEYS, true), 500);
        $account = Account::whereKey($accountId)->sharedLock()->firstOrFail();
        $ancestors = DB::table('account_closure')->where('descendant_id', $accountId)->pluck('ancestor_id')->all();
        $custom = DB::table('operation_stop_targets')->where('account_id', $accountId)->sharedLock()->pluck('stop_id')->all();
        $type = array_search($account->type->value, OperationAccess::TYPES, true);
        $rules = OperationStop::where('active', true)->where(function ($query) use ($type, $custom): void {
            $query->where('scope', 'all')->orWhere('scope', $type ?: 'none')->orWhere(fn ($query) => $query->where('scope', 'custom')->whereIn('id', $custom));
        })->orderByDesc('id')->sharedLock()->get();
        foreach ($rules as $rule) {
            if ($account->type === AccountType::System) {
                continue;
            }
            $roots = $rule->include_descendants ? $ancestors : [$accountId];
            if ($rule->scope_roots !== null && ! array_intersect($rule->scope_roots, $roots)) {
                continue;
            }
            if (in_array('app', $rule->actions, true) || in_array($key, $rule->actions, true)) {
                abort(423, 'موقوف: '.$rule->reason);
            }
        }
        foreach (DirectStop::whereIn('account_id', $ancestors)->sharedLock()->get() as $direct) {
            abort_if(($direct->stops['app'] ?? false) || ($direct->stops[$key] ?? false), 423, 'العملية موقوفة لهذه الجهة أو الجهة الأعلى.');
        }
    }
}
