<?php

namespace App\Services\Operations;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Operations\AccountArchive;
use App\Models\Operations\OperationStop;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class OperationAccess
{
    public const TYPES = ['main' => 'main_agent', 'branch' => 'sub_agent', 'subbranch' => 'sub_branch', 'pos' => 'pos'];

    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function requireSecurity(User $actor, bool $write = false, bool $owner = false): void
    {
        $this->authority->require($actor, 'security.view');
        if ($owner) {
            $this->authority->require($actor, 'security.time');
        }
        abort_unless($actor->membership->account->type === AccountType::System, 403);
    }

    public function requireActions(User $actor, array $actions): void
    {
        $this->requireSecurity($actor);
        foreach ($actions as $action) {
            $this->authority->require($actor, 'security.'.$action);
        }
    }

    public function accounts(User $actor): Builder
    {
        return $this->scope->query($actor)->where('type', '<>', AccountType::System);
    }

    public function timeMembers(User $actor): Builder
    {
        $this->requireSecurity($actor, false, true);
        $permissions = $actor->membership->permissions();

        return $this->archiveMembers($actor)->where('kind', 'employee')->where('user_id', '<>', $actor->id)->whereHas('account', fn ($account) => $account->whereNull('archived_at'))
            ->whereNotExists(function ($profile) use ($permissions): void {
                $profile->selectRaw('1')->from('permission_profile_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
                    ->whereColumn('permission_profile_id', 'account_memberships.permission_profile_id')->whereNotIn('permissions.name', $permissions);
            });
    }

    public function canRestoreGlobal(User $actor): bool
    {
        foreach (['app', 'login', 'sales', 'printing', 'import'] as $key) {
            if (! in_array('security.'.$key, $actor->membership->permissions(), true)) {
                return false;
            }
        }

        return $actor->membership->kind === 'owner' || ! Account::whereNotIn('id', $this->scope->query($actor, true)->select('accounts.id'))->exists();
    }

    public function stops(User $actor): Builder
    {
        $this->requireSecurity($actor);
        $query = OperationStop::query();
        if ($actor->membership->kind === 'owner') {
            return $query;
        }
        $visible = $this->scope->query($actor, true)->select('accounts.id');

        return $query->whereNotNull('scope_roots')->whereExists(function ($roots): void {
            $roots->selectRaw('1')->from('operation_stop_scope_roots')->whereColumn('stop_id', 'operation_stops.id');
        })->whereNotExists(function ($roots) use ($visible): void {
            $roots->selectRaw('1')->from('operation_stop_scope_roots')->join('account_closure', 'ancestor_id', '=', 'operation_stop_scope_roots.account_id')
                ->whereColumn('stop_id', 'operation_stops.id')->where(fn ($q) => $q->where('operation_stops.include_descendants', true)->orWhere('depth', 0))->whereNotIn('descendant_id', $visible);
        });
    }

    public function matchedAccounts(OperationStop $stop): Builder
    {
        $query = Account::where('type', '<>', AccountType::System)->whereNull('archived_at');
        if ($stop->scope === 'custom') {
            $query->whereIn('id', DB::table('operation_stop_targets')->where('stop_id', $stop->id)->select('account_id'));
        } elseif ($stop->scope !== 'all') {
            $query->where('type', self::TYPES[$stop->scope]);
        }
        if ($stop->scope_roots !== null) {
            $query->whereIn('id', DB::table('account_closure')->whereIn('ancestor_id', $stop->scope_roots)->when(! $stop->include_descendants, fn ($q) => $q->where('depth', 0))->select('descendant_id'));
        }

        return $query;
    }

    public function archives(User $actor): Builder
    {
        $this->authority->require($actor, 'account.view');
        $this->authority->require($actor, 'agents.archiveView');

        return AccountArchive::whereIn('account_id', $this->scope->query($actor, true)->select('accounts.id'));
    }

    public function archiveTarget(User $actor, int $id, bool $lock = false): Account
    {
        $this->authority->require($actor, 'account.view');
        $this->authority->require($actor, 'agents.archive');
        $query = $this->scope->query($actor, true);
        $account = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        abort_if($account->type === AccountType::System || $account->id === $actor->membership->account_id, 403, 'لا يمكن أرشفة حسابك أو مدير النظام.');

        return $account;
    }

    /** Membership coverage is checked even for searching archived login emails. */
    public function archiveMembers(User $actor): Builder
    {
        $visible = $this->scope->query($actor, true)->select('accounts.id');
        $employer = DB::table('account_closure')->select('descendant_id')->whereColumn('ancestor_id', 'account_memberships.account_id');

        return AccountMembership::whereIn('account_id', $visible)->where(function ($query) use ($visible, $employer): void {
            $query->where('kind', 'owner')->orWhere(function ($staff) use ($visible, $employer): void {
                $staff->where('kind', 'employee')->whereExists(function ($roots): void {
                    $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'account_memberships.id');
                })->whereNotExists(function ($roots) use ($employer): void {
                    $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'account_memberships.id')->whereNotIn('account_id', $employer);
                })->whereNotExists(function ($roots) use ($visible): void {
                    $roots->selectRaw('1')->from('membership_scope_roots')->join('account_closure', 'ancestor_id', '=', 'membership_scope_roots.account_id')
                        ->whereColumn('membership_id', 'account_memberships.id')->where(fn ($q) => $q->where('account_memberships.include_descendants', true)->orWhere('depth', 0))->whereNotIn('descendant_id', $visible);
                });
            });
        });
    }
}
