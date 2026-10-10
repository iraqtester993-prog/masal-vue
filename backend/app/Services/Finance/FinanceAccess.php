<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\CatalogProvider;
use App\Models\User;
use App\Services\AccountScope;
use App\Services\ManagementAuthority;
use App\Services\Operations\OperationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceAccess
{
    public function __construct(private AccountScope $scope, private ManagementAuthority $authority) {}

    public function require(User $actor, string $permission, bool $system = false): void
    {
        $this->authority->require($actor, $permission);
        $this->authority->require($actor, 'account.view');
        $module = explode('.', $permission)[0];
        if (in_array($module, ['wallets', 'invoices', 'prices'], true) && $permission !== $module.'.view') {
            $this->authority->require($actor, $module.'.view');
        }
        if ($system) {
            abort_unless($actor->membership->account->type === AccountType::System && $this->scope->query($actor)->whereKey($actor->membership->account_id)->exists(), 403);
        }
    }

    public function accounts(User $actor): Builder
    {
        return $this->scope->query($actor);
    }

    public function account(User $actor, int $id, string $permission, bool $lock = false): Account
    {
        $this->require($actor, $permission);
        $account = ($lock ? $this->scope->query($actor)->lockForUpdate() : $this->scope->query($actor))->findOrFail($id);
        abort_unless($account->isOperational(), 409, 'الحساب أو أحد الحسابات الأعلى موقوف.');
        if ($lock) {
            app(OperationGuard::class)->assertAllowed($account->id, 'app');
        }

        return $account;
    }

    public function service(string $service, string $currency): void
    {
        if (! in_array($currency, ['IQD'], true)) {
            throw ValidationException::withMessages(['currency' => 'اختر عملة صحيحة.']);
        }
        if (in_array($service, ['voucher', 'topup', 'cash'], true)) {
            return;
        }
        if (preg_match('/\Aapi:([1-9][0-9]*)\z/', $service, $match) && CatalogProvider::whereKey($match[1])->where('connection', 'API')->where('status', 'active')->exists()) {
            return;
        }
        throw ValidationException::withMessages(['service' => 'اختر محفظة خدمة صحيحة ومفعلة.']);
    }

    public function own(User $actor, string $permission, bool $lock = false): Account
    {
        return $this->account($actor, (int) $actor->membership->account_id, $permission, $lock);
    }

    public function transfer(User $actor, int $fromId, int $toId): array
    {
        $from = $this->own($actor, 'wallets.transfer', true);
        abort_unless($from->id === $fromId && $from->type !== AccountType::System && $from->type !== AccountType::Pos, 403, 'التمويل ينفذ من حساب الوكيل الممول فقط.');
        $to = $this->account($actor, $toId, 'wallets.transfer', true);
        abort_unless($to->parent_id === $from->id, 403, 'اختر تابعاً مباشراً لحسابك.');

        return [$from, $to];
    }

    public function bulkFunder(User $actor, int $fromId, bool $lock = false): Account
    {
        $this->require($actor, 'wallets.bulk');
        $from = $this->account($actor, $fromId, 'wallets.transfer', $lock);
        abort_unless(! in_array($from->type, [AccountType::System, AccountType::Pos], true), 403, 'اختر محفظة وكيل ممول.');
        abort_unless($actor->membership->account->type === AccountType::System || $actor->membership->account_id === $from->id, 403, 'التمويل من محفظة حسابك فقط.');

        return $from;
    }

    public function descendant(User $actor, Account $from, int $toId, string $permission): Account
    {
        $to = $this->account($actor, $toId, $permission, true);
        abort_unless(DB::table('account_closure')->where('ancestor_id', $from->id)->where('descendant_id', $to->id)->where('depth', '>', 0)->exists(), 403, 'اختر حسابًا تابعًا للوكيل الممول.');

        return $to;
    }
}
