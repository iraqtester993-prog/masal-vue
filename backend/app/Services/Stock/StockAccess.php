<?php

namespace App\Services\Stock;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockOrder;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use App\Services\Operations\OperationGuard;
use Illuminate\Database\Eloquent\Builder;

class StockAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission, bool $system = false): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
        $module = explode('.', $permission)[0];
        if ($permission !== $module.'.view' && in_array($module, ['inventory', 'claims', 'exports', 'import'], true)) {
            $this->authority->require($actor, $module.'.view');
        }
        abort_if($actor->membership->account->type === AccountType::Pos, 403);
        if ($system) {
            abort_unless($actor->membership->account->type === AccountType::System, 403);
        }
    }

    public function accounts(User $actor): Builder
    {
        return $this->scope->query($actor)->where('type', AccountType::MainAgent);
    }

    public function requireAnyView(User $actor): void
    {
        $views = array_intersect(['import.view', 'inventory.view', 'claims.view', 'exports.view'], $actor->membership->permissions());
        abort_if($views === [], 403);
        $this->require($actor, array_values($views)[0]);
    }

    public function account(User $actor, int $id, string $permission, bool $lock = false): Account
    {
        $this->require($actor, $permission);
        $query = $this->accounts($actor);

        $account = ($lock ? $query->lockForUpdate() : $query)->findOrFail($id);
        if ($lock) {
            abort_unless($account->isOperational(), 409, 'الحساب أو أحد الحسابات الأعلى موقوف.');
            app(OperationGuard::class)->assertAllowed($account->id, str_starts_with($permission, 'import.') ? 'import' : 'app');
        }

        return $account;
    }

    public function orders(User $actor): Builder
    {
        return StockOrder::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
    }

    public function batches(User $actor): Builder
    {
        return StockBatch::whereIn('account_id', $this->accounts($actor)->select('accounts.id'));
    }
}
