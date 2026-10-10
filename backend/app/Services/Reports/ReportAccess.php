<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\AccountScope;
use App\Services\CatalogAccess;
use App\Services\ManagementAuthority;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportAccess
{
    public function __construct(private AccountScope $scope, private CatalogAccess $catalog, private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission = 'reports.view'): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
    }

    public function allows(User $actor, string $permission): bool
    {
        $permission = match ($permission) {
            'users.view' => 'staff.view',
            'agents.view','pos.view' => 'account.view',
            'printing.view','settings.view' => 'security.view',
            'audit.view' => 'reports.audit',
            default => $permission,
        };

        return in_array($permission, $actor->membership->permissions(), true);
    }

    public function accounts(User $actor, array $filters = [], bool $includeArchived = false): Builder
    {
        if ($includeArchived) {
            $this->authority->require($actor, 'agents.archiveView');
        }
        $query = $this->scope->query($actor, $includeArchived);
        if (! empty($filters['agent_id'])) {
            $query->whereIn('id', DB::table('account_closure')->where('ancestor_id', $filters['agent_id'])->select('descendant_id'));
        }
        if (! empty($filters['pos_id'])) {
            $query->whereKey($filters['pos_id']);
        }
        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }

        return $query;
    }

    public function products(User $actor, array $filters = []): Builder
    {
        $query = $this->catalog->products($actor);
        if (! empty($filters['product_id'])) {
            $query->whereKey($filters['product_id']);
        }
        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }

        return $query;
    }

    public function validateFilters(User $actor, array $filters, bool $includeArchived = false): void
    {
        foreach (['agent_id', 'pos_id'] as $key) {
            if (! empty($filters[$key])) {
                $query = $this->accounts($actor, [], $includeArchived)->whereKey($filters[$key]);
                if ($key === 'pos_id') {
                    $query->where('type', 'pos');
                } else {
                    $query->whereIn('type', ['main_agent', 'sub_agent', 'sub_branch']);
                }
                abort_unless($query->exists(), 404);
            }
        }
        if (! empty($filters['pos_id']) && ! empty($filters['agent_id'])) {
            abort_unless(DB::table('account_closure')->where('ancestor_id', $filters['agent_id'])->where('descendant_id', $filters['pos_id'])->exists(), 404);
        }
        if (! empty($filters['product_id'])) {
            abort_unless($this->products($actor)->whereKey($filters['product_id'])->exists(), 404);
        }
        if (! empty($filters['provider_id'])) {
            abort_unless($this->products($actor)->where('provider_id', $filters['provider_id'])->exists(), 404);
        }
        if (! empty($filters['city'])) {
            abort_unless($this->accounts($actor, [], $includeArchived)->where('city', $filters['city'])->exists(), 404);
        }
    }

    public function users(User $actor, array $filters = []): array
    {
        $visible = $this->scope->query($actor)->select('accounts.id');

        return DB::table('account_memberships as member')->whereIn('member.account_id', $this->accounts($actor, $filters)->select('accounts.id'))->where(function ($members) use ($visible): void {
            $members->where('member.kind', 'owner')->orWhere(function ($employees) use ($visible): void {
                $employees->where('member.kind', 'employee')->whereExists(function ($roots): void {
                    $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'member.id');
                })->whereNotExists(function ($roots): void {
                    $roots->selectRaw('1')->from('membership_scope_roots')->whereColumn('membership_id', 'member.id')->whereNotIn('account_id', DB::table('account_closure')->select('descendant_id')->whereColumn('ancestor_id', 'member.account_id'));
                })->whereNotExists(function ($expanded) use ($visible): void {
                    $expanded->selectRaw('1')->from('membership_scope_roots')->join('account_closure', 'ancestor_id', '=', 'membership_scope_roots.account_id')->whereColumn('membership_id', 'member.id')->where(function ($depth): void {
                        $depth->where('member.include_descendants', true)->orWhere('depth', 0);
                    })->whereNotIn('descendant_id', $visible);
                });
            });
        })->distinct()->pluck('member.user_id')->map(fn ($id): int => (int) $id)->all();
    }
}
