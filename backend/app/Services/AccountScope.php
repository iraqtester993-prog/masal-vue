<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AccountScope
{
    public function members(User $actor, int $accountId, bool $includeArchived = false): Builder
    {
        $visibleIds = $this->query($actor, $includeArchived)->select('accounts.id');
        $employerIds = DB::table('account_closure')->select('descendant_id')->where('ancestor_id', $accountId);

        return AccountMembership::where('account_id', $accountId)->where(function ($query) use ($visibleIds, $employerIds): void {
            $query->where('kind', 'owner')->orWhere(function ($employees) use ($visibleIds, $employerIds): void {
                $employees->where('kind', 'employee')
                    ->whereExists(function ($roots): void {
                        $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'account_memberships.id');
                    })
                    ->whereNotExists(function ($roots) use ($employerIds): void {
                        $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'account_memberships.id')->whereNotIn('account_id', $employerIds);
                    })
                    ->whereNotExists(function ($expanded) use ($visibleIds): void {
                        $expanded->selectRaw('1')->from('membership_scope_roots')->join('account_closure', 'ancestor_id', '=', 'membership_scope_roots.account_id')
                            ->whereColumn('membership_id', 'account_memberships.id')
                            ->where(function ($depth): void {
                                $depth->where('account_memberships.include_descendants', true)->orWhere('depth', 0);
                            })
                            ->whereNotIn('descendant_id', $visibleIds);
                    });
            });
        });
    }

    public function query(User $user, bool $includeArchived = false): Builder
    {
        $membership = $user->membership;
        $query = Account::query()->when(! $includeArchived, fn ($q) => $q->whereNull('accounts.archived_at'));
        if (! $user->isOperational() || ! $membership || ! in_array('account.view', $membership->permissions(), true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($membership->kind === 'employee') {
            $roots = $membership->scopeRoots();
            $assignedIds = $membership->include_descendants
                ? DB::table('account_closure')->select('descendant_id')->whereIn('ancestor_id', $roots) : $roots;

            return $query->whereIn('id', $assignedIds)->whereIn('id', DB::table('account_closure')->select('descendant_id')->where('ancestor_id', $membership->account_id));
        }

        return match ($membership->account->type) {
            AccountType::System => $query,
            AccountType::Pos => $query->whereKey($membership->account_id),
            default => $query->whereIn('id', DB::table('account_closure')
                ->select('descendant_id')->where('ancestor_id', $membership->account_id)),
        };
    }
}
