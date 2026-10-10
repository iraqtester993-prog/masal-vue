<?php

namespace App\Services\Maps;

use App\Enums\AccountType;
use App\Models\Maps\UserPresence;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MapAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function users(User $actor, array $filters = []): Builder
    {
        $this->authority->require($actor, 'map.view');
        $visible = $this->scope->query($actor)->select('accounts.id');
        $query = User::query()->with(['membership.account.parent', 'membership.profile'])->whereHas('membership', function ($members) use ($actor, $visible): void {
            $members->where(function ($allowed) use ($actor, $visible): void {
                $allowed->where(function ($owners) use ($visible): void {
                    $owners->where('kind', 'owner')->whereIn('account_id', clone $visible)->whereHas('account', fn ($accounts) => $accounts->where('type', '<>', AccountType::System->value));
                });
                if (in_array('staff.view', $actor->membership->permissions(), true)) {
                    $allowed->orWhere(function ($employees) use ($visible): void {
                        $employees->where('kind', 'employee')->whereIn('account_id', clone $visible)
                            ->whereExists(fn ($roots) => $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'account_memberships.id'))
                            ->whereNotExists(function ($roots) use ($visible): void {
                                $roots->selectRaw('1')->from('membership_scope_roots')->join('account_closure', 'ancestor_id', '=', 'membership_scope_roots.account_id')->whereColumn('membership_id', 'account_memberships.id')
                                    ->where(fn ($depth) => $depth->where('account_memberships.include_descendants', true)->orWhere('depth', 0))->whereNotIn('descendant_id', clone $visible);
                            });
                    });
                }
                if ($actor->membership->kind === 'employee') {
                    $allowed->orWhere('user_id', $actor->id);
                }
            });
        });
        if (! empty($filters['branch_id'])) {
            $branch = $this->authority->account($actor, (int) $filters['branch_id'], 'map.view');
            $query->whereHas('membership', fn ($membership) => $membership->whereIn('account_id', DB::table('account_closure')->select('descendant_id')->where('ancestor_id', $branch->id)));
        }
        if (! empty($filters['type'])) {
            $query->whereHas('membership', fn ($membership) => $filters['type'] === 'employee' ? $membership->where('kind', 'employee') : $membership->where('kind', 'owner')->whereHas('account', fn ($account) => $account->where('type', $filters['type'])));
        }
        if (! empty($filters['query'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['query']).'%';
            $query->where(fn ($search) => $search->whereRaw("users.name LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('membership.account', fn ($account) => $account->whereRaw("accounts.name LIKE ? ESCAPE '!'", [$pattern])));
        }
        if (array_key_exists('user_ids', $filters)) {
            $query->whereIn('id', $filters['user_ids']);
        }

        return $query->orderBy('users.id');
    }

    public function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    public function heartbeat(User $actor, Request $request, array $data = []): UserPresence
    {
        return DB::transaction(function () use ($actor, $request, $data): UserPresence {
            app(MutationGuard::class)->lockRuntime();

            return UserPresence::updateOrCreate(['session_hash' => $this->sessionHash($request)], ['user_id' => $actor->id, 'session_version' => $actor->session_version, 'connected' => true, 'last_seen_at' => now()] + array_intersect_key($data, array_flip(['device_model', 'app_version'])));
        });
    }

    public function disconnect(Request $request): void
    {
        DB::transaction(function () use ($request): void {
            app(MutationGuard::class)->lockRuntime();
            UserPresence::where('session_hash', $this->sessionHash($request))->where('user_id', $request->user()->id)->update(['connected' => false, 'sharing' => false, 'consent_version' => DB::raw('consent_version + 1'), 'last_seen_at' => now()]);
        });
    }

    public function locationReady(User $actor, Request $request): bool
    {
        return UserPresence::where('user_id', $actor->id)->where('session_hash', $this->sessionHash($request))->where('session_version', $actor->session_version)->where('connected', true)->where('sharing', true)->whereNotNull('location_at')->whereNotNull('latitude')->whereNotNull('longitude')->exists();
    }

    /** Coordinates and user metadata are released only after users() has scoped the records. */
    public function resources($users, User $actor): array
    {
        $ids = $users->pluck('id');
        $presences = UserPresence::whereIn('user_id', $ids)->orderByDesc('last_seen_at')->orderByDesc('id')->get()->groupBy('user_id');
        $accountIds = $users->map(fn ($user) => $user->membership->account_id)->unique();
        $visibleIds = array_fill_keys($this->scope->query($actor)->pluck('id')->all(), true);
        $locations = DB::table('account_locations')->whereIn('account_id', $accountIds)->get()->keyBy('account_id');
        $networks = DB::table('account_closure as c')->join('accounts as a', 'a.id', '=', 'c.ancestor_id')->whereIn('c.descendant_id', $accountIds)->where('a.type', AccountType::MainAgent->value)->get(['c.descendant_id', 'a.id', 'a.name', 'a.color'])->keyBy('descendant_id');
        $trees = DB::table('account_closure as c')->join('accounts as a', 'a.id', '=', 'c.ancestor_id')->whereIn('c.descendant_id', $accountIds)->get(['c.descendant_id', 'c.depth', 'a.id', 'a.parent_id', 'a.status', 'a.archived_at'])->groupBy('descendant_id');
        $activeAccounts = [];
        foreach ($trees as $accountId => $nodes) {
            $nodes = $nodes->keyBy('id');
            $next = (int) $accountId;
            $depth = 0;
            $seen = [];
            $valid = true;
            while ($next !== null) {
                $node = $nodes->get($next);
                if (! $node || isset($seen[$next]) || ($node->status !== 'active' || $node->archived_at !== null) || (int) $node->depth !== $depth) {
                    $valid = false;
                    break;
                }
                $seen[$next] = true;
                $next = $node->parent_id === null ? null : (int) $node->parent_id;
                $depth++;
            }
            $activeAccounts[$accountId] = $valid && count($seen) === $nodes->count();
        }

        return $users->map(function (User $user) use ($presences, $locations, $networks, $activeAccounts, $visibleIds): array {
            $account = $user->membership->account;
            $sessions = $presences->get($user->id, collect());
            $presence = $sessions->first();
            $fixed = $locations->get($account->id);
            $network = $networks->get($account->id);
            if ($network && ! isset($visibleIds[$network->id])) {
                $network = null;
            }
            $device = $presence && $presence->latitude !== null && $presence->longitude !== null;
            $location = $device ? ['lat' => $presence->latitude, 'lng' => $presence->longitude] : ($fixed ? ['lat' => (float) $fixed->latitude, 'lng' => (float) $fixed->longitude] : null);
            $employee = $user->membership->kind === 'employee';
            $kind = $employee ? 'موظف' : match ($account->type) {
                AccountType::MainAgent => 'وكيل رئيسي',AccountType::SubAgent => 'فرع',AccountType::SubBranch => 'فرع فرعي',AccountType::Pos => 'نقطة بيع',default => 'مدير النظام'
            };
            $active = $user->status === 'active' && $user->membership->status === 'active' && ($activeAccounts[$account->id] ?? false) && (! $employee || ($user->membership->profile?->status === 'active' && $user->membership->profile->account_id === $account->id));
            $online = $active && $sessions->contains(fn ($row) => $row->connected && $row->session_version === $user->session_version && $row->last_seen_at->greaterThan(now()->subSeconds(120)) && ! $row->last_seen_at->isFuture());

            return ['id' => $user->id, 'name' => $user->name, 'role' => $employee ? 'employee' : $account->type->value, 'kind' => $kind, 'symbol' => $employee ? '●' : match ($account->type) {
                AccountType::MainAgent => '◆',AccountType::Pos => '▲',default => '■'
            }, 'account_id' => $account->id, 'parent_id' => isset($visibleIds[$account->parent_id]) ? $account->parent_id : null, 'agent' => $account->type === AccountType::Pos ? (isset($visibleIds[$account->parent_id]) ? $account->parent_id : null) : $account->id, 'account' => $account->name, 'parentName' => isset($visibleIds[$account->parent_id]) ? ($account->parent?->name ?? '') : '', 'phone' => $account->phone ?? '', 'city' => $account->city ?? '', 'address' => $account->address ?? '', 'active' => $active, 'online' => (bool) $online, 'lastSeen' => $presence?->last_seen_at?->toIso8601String(), 'location' => $location, 'source' => $device ? 'آخر موقع للجهاز' : ($location ? 'موقع الحساب المسجل' : 'بدون موقع'), 'locationTime' => $device ? $presence->location_at?->toIso8601String() : $fixed?->updated_at, 'accuracy' => $device ? $presence->accuracy : null, 'device_model' => $presence?->device_model ?? '', 'app_version' => $presence?->app_version ?? '', 'networkId' => $network?->id, 'networkName' => $network?->name ?? 'حسابات النظام', 'networkColor' => preg_match('/^#[0-9a-f]{6}$/i', $network?->color ?? '') ? $network->color : '#0898b5'];
        })->all();
    }
}
