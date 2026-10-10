<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\NetworkRepresentative;
use App\Models\OrderSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReferenceAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission, bool $global = false): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
        $module = explode('.', $permission)[0];
        if ($permission !== $module.'.view') {
            $this->authority->require($actor, $module.'.view');
        }
        abort_if($actor->membership->account->type === AccountType::Pos, 403);
        if ($module === 'governorates') {
            abort_unless($actor->membership->account->type === AccountType::System && $actor->membership->kind === 'owner', 403);
        }
        if ($global) {
            abort_unless($actor->membership->account->type === AccountType::System && $this->scope->query($actor)->whereKey($actor->membership->account_id)->exists(), 403);
        }
    }

    public function agents(User $actor): Builder
    {
        return $this->scope->query($actor)->whereIn('type', [AccountType::MainAgent->value, AccountType::SubAgent->value, AccountType::SubBranch->value]);
    }

    public function networks(User $actor): Builder
    {
        if ($actor->membership->account->type === AccountType::System && $this->scope->query($actor)->whereKey($actor->membership->account_id)->exists()) {
            return Account::where('type', AccountType::MainAgent);
        }
        $visible = $this->agents($actor)->select('accounts.id');

        return Account::where('type', AccountType::MainAgent)->whereIn('id', DB::table('account_closure')->select('ancestor_id')->whereIn('descendant_id', $visible));
    }

    public function sources(User $actor): Builder
    {
        return OrderSource::whereIn('network_account_id', $this->networks($actor)->select('accounts.id'));
    }

    public function representatives(User $actor): Builder
    {
        return NetworkRepresentative::whereIn('agent_account_id', $this->agents($actor)->select('accounts.id'));
    }

    public function writableNetwork(User $actor, ?int $networkId): Account
    {
        if ($networkId === null && $actor->membership->account->type !== AccountType::System) {
            $networkId = $this->networks($actor)->value('id');
        }
        $network = $this->networks($actor)->lockForUpdate()->findOrFail($networkId);
        abort_unless($this->scope->query($actor)->whereKey($network->id)->exists(), 403, 'إدارة المصادر تتطلب نطاق الوكيل الرئيسي.');

        return $network;
    }
}
