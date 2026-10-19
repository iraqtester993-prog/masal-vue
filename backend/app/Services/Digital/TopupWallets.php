<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\TopupGrant;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Wallet;
use App\Models\User;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use App\Services\ManagementAuthority;
use App\Support\RequestReadCache;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TopupWallets
{
    public function __construct(private Ledger $ledger) {}

    public function initialized(TopupGrant $grant): bool
    {
        $query = DB::table('topup_wallet_bindings')->where('grant_id', $grant->id)->where('role', 'main');

        return (DB::transactionLevel() > 0 ? $query->sharedLock() : $query)->first(['id']) !== null;
    }

    public function assertNoUnboundMoney(): void
    {
        abort_if(Wallet::where('service', 'topup')->whereNotIn('id', DB::table('topup_wallet_bindings')->select('wallet_id'))
            ->where(fn ($query) => $query->where('balance_minor', '<>', 0)->orWhere('held_minor', '<>', 0))->exists(), 409,
            'توجد أرصدة Topup قديمة غير مرتبطة؛ يجب مطابقتها قبل تفعيل التمويل.');
    }

    public function bootstrap(TopupGrant $grant, User $actor): Wallet
    {
        abort_unless(DB::transactionLevel() > 0 && $grant->connection_id !== null && $grant->target_account_id === $grant->main_account_id, 409, 'حصة Topup غير صالحة للتمويل.');
        app(ManagementAuthority::class)->systemAccess($actor, 'topup.allocate');
        abort_unless($actor->membership->account_id === $grant->from_account_id, 403);
        if ($this->initialized($grant)) {
            return $this->bound($grant, $grant->target_account_id, 'main');
        }
        $legacyHeld = (int) DigitalOrder::where('topup_grant_id', $grant->id)->where('reservation_active', true)->where('budget_basis', 'legacy_main')->sum('admin_price_minor');
        abort_unless($grant->held_minor === $legacyHeld, 409, 'حجوزات Topup القديمة لا تطابق الحصة؛ يلزم المطابقة قبل تهيئة التمويل.');
        $this->assertNoUnboundMoney();
        $main = Account::whereKey($grant->target_account_id)->where('type', AccountType::MainAgent)->firstOrFail();
        $system = Account::whereKey($grant->from_account_id)->where('type', AccountType::System)->firstOrFail();
        abort_unless(DigitalConnection::whereKey($grant->connection_id)->where('provider', 'topup')->where('account_id', $system->id)->exists(), 409, 'حصة Topup يجب أن ترتبط بتوكن الإدارة المركزي.');
        $clearing = $this->ledger->wallet($system->id, 'topup', 'IQD', 'external');
        $mainWallet = $this->ledger->wallet($main->id, 'topup', 'IQD');
        $margin = $this->ledger->wallet($main->id, 'topup', 'IQD', 'external');
        foreach ([[$clearing, null, 'clearing'], [$mainWallet, $grant->id, 'main'], [$margin, $grant->id, 'margin']] as [$wallet, $grantId, $role]) {
            $existing = DB::table('topup_wallet_bindings')->where('wallet_id', $wallet->id)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(($existing->grant_id === null ? null : (int) $existing->grant_id) === $grantId && $existing->role === $role, 409, 'المحفظة مرتبطة بحصة Topup مختلفة.');
            } else {
                DB::table('topup_wallet_bindings')->insert(['wallet_id' => $wallet->id, 'grant_id' => $grantId, 'role' => $role, 'created_at' => now()]);
            }
        }
        if ($grant->balance_minor > 0) {
            $tx = $this->ledger->pair($actor, 'topup-opening', 'TP:OPEN:'.$grant->id, ['grant_id' => $grant->id, 'connection_id' => $grant->connection_id, 'balance_minor' => $grant->balance_minor],
                $clearing, $mainWallet, $grant->balance_minor, 'رصيد Topup الافتتاحي للوكيل');
            $this->event($tx, $grant, 'opening', 'grant:'.$grant->id.':opening');
        }
        $mainWallet = Wallet::whereKey($mainWallet->id)->lockForUpdate()->firstOrFail();
        abort_unless($grant->held_minor <= $mainWallet->balance_minor, 409, 'حجوزات Topup القديمة تتجاوز الرصيد المتبقي.');
        if ($grant->held_minor > 0) {
            $this->hold($mainWallet, $grant->held_minor);
        }

        return $mainWallet->fresh();
    }

    public function bound(TopupGrant $grant, int $accountId, string $role, bool $lock = true): Wallet
    {
        $kind = $role === 'margin' ? 'external' : 'account';
        $wallet = $lock ? $this->ledger->wallet($accountId, 'topup', 'IQD', $kind)
            : Wallet::where('account_id', $accountId)->where('service', 'topup')->where('currency', 'IQD')->where('kind', $kind)->first();
        abort_unless($wallet, 409, 'رصيد Topup لم يهيأ بعد؛ راجع مدير النظام.');
        $query = DB::table('topup_wallet_bindings')->where('wallet_id', $wallet->id)->where('grant_id', $grant->id)->where('role', $role);
        $binding = ($lock ? $query->sharedLock() : $query)->first(['id']);
        abort_unless($binding, 409, 'رصيد Topup لم يهيأ بعد؛ راجع مدير النظام.');

        return $wallet;
    }

    public function point(TopupGrant $grant, Account $point): Wallet
    {
        abort_unless($point->type === AccountType::Pos && DB::table('account_closure')->where('ancestor_id', $grant->target_account_id)->where('descendant_id', $point->id)->where('depth', '>', 0)->exists(), 403,
            'اختر نقطة بيع تابعة لحصة الوكيل الممول.');
        $wallet = $this->ledger->wallet($point->id, 'topup', 'IQD');
        $binding = DB::table('topup_wallet_bindings')->where('wallet_id', $wallet->id)->lockForUpdate()->first();
        if (! $binding) {
            abort_unless($wallet->balance_minor === 0 && $wallet->held_minor === 0, 409, 'رصيد Topup القديم للنقطة غير مرتبط؛ يلزم مطابقته.');
            DB::table('topup_wallet_bindings')->insert(['wallet_id' => $wallet->id, 'grant_id' => $grant->id, 'role' => 'point', 'created_at' => now()]);
        } else {
            abort_unless((int) $binding->grant_id === $grant->id && $binding->role === 'point', 409, 'رصيد النقطة مرتبط بحصة Topup أخرى.');
        }

        return $wallet;
    }

    public function hold(Wallet $wallet, int $change): void
    {
        $current = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
        $held = $current->held_minor + $change;
        if ($held < 0 || $held > $current->balance_minor) {
            throw ValidationException::withMessages(['amount' => $change > 0 ? 'رصيدك غير كافٍ؛ اطلب تمويل Topup من الوكيل.' : 'حجز رصيد Topup غير مطابق للعملية.']);
        }
        $current->update(['held_minor' => $held, 'version' => $current->version + 1]);
        $wallet->setRawAttributes($current->getAttributes(), true);
    }

    private function clearing(TopupGrant $grant): Wallet
    {
        $wallet = $this->ledger->wallet($grant->from_account_id, 'topup', 'IQD', 'external');
        $binding = DB::table('topup_wallet_bindings')->where('wallet_id', $wallet->id)->whereNull('grant_id')->where('role', 'clearing')->sharedLock()->first(['id']);
        abort_unless($binding, 409, 'حساب تسوية Topup غير مهيأ.');

        return $wallet;
    }

    public function allocation(User $actor, TopupGrant $grant, int $balance, string $key): void
    {
        $main = $this->bound($grant, $grant->target_account_id, 'main');
        $change = $balance - $grant->balance_minor;
        if ($change === 0) {
            return;
        }
        $clearing = $this->clearing($grant);
        $tx = $this->ledger->pair($actor, 'topup-allocation', 'TP:ALLOC:'.$key, ['grant_id' => $grant->id, 'balance_minor' => $balance, 'before_minor' => $grant->balance_minor],
            $change > 0 ? $clearing : $main, $change > 0 ? $main : $clearing, abs($change), 'تعديل حصة Topup من الإدارة');
        $this->event($tx, $grant, 'allocation', 'allocation:'.$tx->id);
    }

    public function fund(User $actor, Account $from, Account $to, int $amount, string $key, array $payload, string $reference, string $kind = 'transfer'): LedgerTransaction
    {
        abort_unless($from->type === AccountType::MainAgent && $to->type === AccountType::Pos, 422, 'تمويل Topup من الوكيل الرئيسي إلى نقطة بيع تابعة فقط.');
        $connection = app(TopupAccounting::class)->connection($from);
        abort_unless($connection, 409, 'لا توجد حصة Topup للوكيل.');
        $connection = $connection->newQuery()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
        $grant = app(TopupAccounting::class)->grant($from, true);
        abort_unless($grant && $grant->active && $grant->connection_id === $connection->id && $connection->active && $connection->account_id === $grant->from_account_id && $connection->account->type === AccountType::System, 409, 'حصة Topup أو ربط الخدمة موقوف أو غير مركزي.');
        $main = $this->bound($grant, $from->id, 'main');
        $point = $this->point($grant, $to);
        $tx = $this->ledger->pair($actor, $kind, $key, $payload, $main, $point, $amount, $reference);
        $this->event($tx, $grant, 'funding', 'funding:'.$tx->id);

        return $tx;
    }

    public function recover(User $actor, LedgerTransaction $original, Account $from, Account $point, int $amount, string $key, array $payload): LedgerTransaction
    {
        $metadata = DB::table('topup_finance_events')->where('transaction_id', $original->id)->where('phase', 'funding')->first();
        abort_unless($metadata && $from->type === AccountType::MainAgent && $point->type === AccountType::Pos, 409, 'التمويل القديم غير مرتبط برصيد Topup الموحد؛ يلزم مطابقته.');
        $grant = TopupGrant::whereKey($metadata->grant_id)->lockForUpdate()->firstOrFail();
        abort_unless($grant->target_account_id === $from->id && $grant->connection_id === $metadata->connection_id, 409, 'تغير ربط حصة Topup الأصلية.');
        $debit = $this->bound($grant, $point->id, 'point');
        $credit = $this->bound($grant, $from->id, 'main');
        $tx = $this->ledger->pair($actor, 'recovery', $key, $payload, $debit, $credit, $amount, 'استرجاع تمويل #'.$original->id);
        $this->event($tx, $grant, 'recovery', 'recovery:'.$tx->id);

        return $tx;
    }

    public function settle(DigitalOrder $order, TopupGrant $grant, bool $refund = false): void
    {
        $pointBudget = $order->budget_basis === 'point_budget_v1';
        $actor = User::findOrFail($order->creator_id);
        $base = (int) $order->admin_price_minor;
        $retail = $pointBudget ? $order->quoted_retail_minor : $base;
        abort_unless($base > 0 && $retail >= $base, 409, 'أسعار Topup المحفوظة غير مطابقة.');
        $wallet = $this->bound($grant, $pointBudget ? $order->account_id : $grant->target_account_id, $pointBudget ? 'point' : 'main');
        if ($pointBudget) {
            abort_unless($wallet->id === $order->budget_wallet_id, 409, 'محفظة رصيد العملية غير مطابقة.');
        }
        $clearing = $this->clearing($grant);
        $direction = $refund ? -1 : 1;
        $legs = [['wallet' => $wallet, 'amount' => -$direction * $retail], ['wallet' => $clearing, 'amount' => $direction * $base]];
        if ($retail > $base) {
            $margin = $this->bound($grant, $grant->target_account_id, 'margin');
            abort_unless(! $refund || $margin->balance_minor >= $retail - $base, 409, 'ربح العملية يحتاج تسوية قبل الاسترجاع.');
            $legs[] = ['wallet' => $margin, 'amount' => $direction * ($retail - $base)];
        }
        $phase = $refund ? 'refund' : 'sale';
        $tx = $this->ledger->post($actor, 'topup-'.$phase, 'TP:'.$phase.':'.$order->id, ['order_id' => $order->id, 'request_id' => $order->request_id, 'budget_basis' => $order->budget_basis],
            'topup', 'IQD', 'Topup '.$order->request_id, $legs);
        $this->event($tx, $grant, $phase, 'order:'.$order->id.':'.$phase, $order->id);
    }

    public function event(LedgerTransaction $transaction, TopupGrant $grant, string $phase, string $key, ?int $orderId = null): void
    {
        $old = DB::table('topup_finance_events')->where('event_key', $key)->sharedLock()->first();
        if ($old) {
            abort_unless((int) $old->transaction_id === $transaction->id && (int) $old->grant_id === $grant->id && (int) $old->connection_id === $grant->connection_id && $old->phase === $phase && $old->order_id === $orderId, 409, 'مرجع Topup المالي مستخدم لعملية مختلفة.');

            return;
        }
        DB::table('topup_finance_events')->insert(['transaction_id' => $transaction->id, 'grant_id' => $grant->id, 'connection_id' => $grant->connection_id, 'order_id' => $orderId, 'phase' => $phase, 'event_key' => $key, 'created_at' => now()]);
    }

    public function assertRebindable(TopupGrant $grant): void
    {
        abort_if(DB::table('topup_wallet_bindings as b')->join('finance_wallets as w', 'w.id', '=', 'b.wallet_id')->where('b.grant_id', $grant->id)
            ->where(fn ($query) => $query->where('w.balance_minor', '<>', 0)->orWhere('w.held_minor', '<>', 0))->exists()
            || $grant->balance_minor !== 0 || $grant->held_minor !== 0
            || DigitalOrder::where('topup_grant_id', $grant->id)->where(fn ($query) => $query->where('reservation_active', true)->orWhereIn('status', ['pending', 'review']))->exists(),
            409, 'استرجع أرصدة النقاط والربح المحاسبي وصفّر الحجوزات والعمليات المعلقة قبل تغيير ربط Topup.');
    }

    public function availability(Account $point, int $retail): array
    {
        $balance = $this->balance($point);
        $available = $balance['available'];

        return ['wallet_available' => Money::decimal($available), 'funding_required' => $available < $retail, 'purchasable' => $available >= $retail,
            'availability_reason' => $available >= $retail ? 'available' : ($balance['balance'] === 0 ? 'funding_required' : 'insufficient_balance')];
    }

    public function balance(Account $account): array
    {
        return RequestReadCache::remember('TopupWallets.balance:'.$account->id, function () use ($account): array {
            $role = match ($account->type) {
                AccountType::MainAgent => 'main', AccountType::Pos => 'point', default => null,
            };
            $grant = $role === null ? null : app(TopupAccounting::class)->grant($account);
            $wallet = $grant ? Wallet::where('account_id', $account->id)->where('service', 'topup')->where('currency', 'IQD')->where('kind', 'account')
                ->whereIn('id', DB::table('topup_wallet_bindings')->where('grant_id', $grant->id)->where('role', $role)->select('wallet_id'))->first() : null;

            return ['balance' => $wallet?->balance_minor ?? 0, 'held' => $wallet?->held_minor ?? 0, 'available' => ($wallet?->balance_minor ?? 0) - ($wallet?->held_minor ?? 0)];
        });
    }

    public function summary(mixed $ids, array $permissions): ?array
    {
        $grants = TopupGrant::whereNotNull('connection_id')->whereIn('target_account_id', clone $ids);
        if (! (clone $grants)->exists()) {
            return null;
        }
        $allocation = (clone $grants)->selectRaw('SUM(balance_minor) AS balance, SUM(held_minor) AS held, SUM(spent_minor) AS spent')->first();
        $grantIds = (clone $grants)->select('id');
        $points = DB::table('topup_wallet_bindings as b')->join('finance_wallets as w', 'w.id', '=', 'b.wallet_id')->where('b.role', 'point')
            ->whereIn('b.grant_id', clone $grantIds)->whereIn('w.account_id', clone $ids)->selectRaw('COALESCE(SUM(w.balance_minor), 0) AS balance, COALESCE(SUM(w.held_minor), 0) AS held')->first();
        $margin = DB::table('topup_wallet_bindings as b')->join('finance_wallets as w', 'w.id', '=', 'b.wallet_id')->where('b.role', 'margin')
            ->whereIn('b.grant_id', clone $grantIds)->whereIn('w.account_id', clone $ids)->sum('w.balance_minor');
        $sales = DB::table('digital_account_totals')->where('provider', 'topup')->where('status', 'succeeded')->whereIn('account_id', clone $ids)->sum('retail_minor');

        $result = ['funded_points_balance' => Money::decimal((int) $points->balance), 'funded_points_held' => Money::decimal((int) $points->held), 'as_of' => now()->toISOString()];
        if (in_array('data.cost', $permissions, true)) {
            $result += ['allocation_balance' => Money::decimal((int) $allocation->balance), 'allocation_held' => Money::decimal((int) $allocation->held), 'administrator_spent' => Money::decimal((int) $allocation->spent)];
        }
        if (in_array('data.profit', $permissions, true) && in_array('data.cost', $permissions, true)) {
            $result['agent_margin_accounting'] = Money::decimal((int) $margin);
        }
        if (in_array('digital.view', $permissions, true)) {
            $result['point_sales'] = Money::decimal((int) $sales);
        }

        return $result;
    }

    public function auditInvariants(?Connection $connection = null): array
    {
        $db = $connection ?? DB::connection();
        $issues = [];
        $check = function (bool $valid, string $code) use (&$issues): void {
            if (! $valid) {
                $issues[] = $code;
            }
        };
        $bindings = $db->table('topup_wallet_bindings as b')->join('finance_wallets as w', 'w.id', '=', 'b.wallet_id');
        $check(! $db->table('finance_wallets')->where('service', 'topup')->whereNotIn('id', $db->table('topup_wallet_bindings')->select('wallet_id'))
            ->where(fn ($query) => $query->where('balance_minor', '<>', 0)->orWhere('held_minor', '<>', 0))->exists(), 'unbound_topup_money');
        $pools = (clone $bindings)->whereNotNull('b.grant_id')->groupBy('b.grant_id')->select('b.grant_id')
            ->selectRaw("SUM(w.balance_minor) AS balance, SUM(CASE WHEN b.role='main' THEN 1 ELSE 0 END) AS mains, SUM(CASE WHEN b.role='margin' THEN 1 ELSE 0 END) AS margins");
        $grants = $db->table('topup_grants as g')->whereNotNull('g.connection_id')->whereColumn('g.target_account_id', 'g.main_account_id');
        $check(! (clone $grants)->join('digital_connections as c', 'c.id', '=', 'g.connection_id')->join('accounts as a', 'a.id', '=', 'g.target_account_id')->join('accounts as s', 's.id', '=', 'g.from_account_id')
            ->where(fn ($query) => $query->where('c.provider', '<>', 'topup')->orWhereColumn('c.account_id', '<>', 'g.from_account_id')->orWhere('a.type', '<>', 'main_agent')->orWhere('s.type', '<>', 'system'))->exists(), 'grant_central_identity');
        $bindingIdentity = "((b.role='clearing' AND b.grant_id IS NULL AND a.type='system' AND w.kind='external') OR (b.role IN ('main','margin') AND a.type='main_agent' AND w.kind=CASE WHEN b.role='margin' THEN 'external' ELSE 'account' END AND g.target_account_id=a.id AND g.main_account_id=a.id AND g.connection_id IS NOT NULL) OR (b.role='point' AND a.type='pos' AND w.kind='account' AND g.connection_id IS NOT NULL AND EXISTS (SELECT 1 FROM account_closure c WHERE c.ancestor_id=g.target_account_id AND c.descendant_id=a.id AND c.depth>0)))";
        $check(! (clone $bindings)->join('accounts as a', 'a.id', '=', 'w.account_id')->leftJoin('topup_grants as g', 'g.id', '=', 'b.grant_id')
            ->whereRaw("(w.service<>'topup' OR w.currency<>'IQD' OR COALESCE(({$bindingIdentity}),0)=0)")->exists(), 'wallet_binding_identity');
        $check(! (clone $grants)->leftJoinSub($pools, 'p', 'p.grant_id', '=', 'g.id')->where(fn ($query) => $query->whereRaw('COALESCE(p.mains,0) <> 1 OR COALESCE(p.margins,0) <> 1 OR COALESCE(p.balance,0) <> g.balance_minor'))->exists(), 'grant_wallet_pool_balance');
        $check(! (clone $bindings)->where('b.role', 'margin')->where(fn ($query) => $query->where('w.balance_minor', '<', 0)->orWhere('w.held_minor', '<>', 0))->exists(), 'nonspendable_margin_bounds');
        $orderHolds = $db->table('digital_orders')->whereNotNull('topup_grant_id')->where('reservation_active', true)->groupBy('topup_grant_id')->select('topup_grant_id')->selectRaw('SUM(admin_price_minor) AS held');
        $check(! (clone $grants)->leftJoinSub($orderHolds, 'o', 'o.topup_grant_id', '=', 'g.id')->whereRaw('g.held_minor <> COALESCE(o.held,0)')->exists(), 'grant_order_holds');
        $legacyHolds = $db->table('digital_orders')->whereNotNull('topup_grant_id')->where('reservation_active', true)->where('budget_basis', 'legacy_main')->groupBy('topup_grant_id')->select('topup_grant_id')->selectRaw('SUM(admin_price_minor) AS held');
        $pointHolds = $db->table('digital_orders')->where('reservation_active', true)->where('budget_basis', 'point_budget_v1')->groupBy('budget_wallet_id')->select('budget_wallet_id')->selectRaw('SUM(quoted_retail_minor) AS held');
        $check(! (clone $bindings)->where('b.role', 'main')->leftJoinSub($legacyHolds, 'o', 'o.topup_grant_id', '=', 'b.grant_id')->whereRaw('w.held_minor <> COALESCE(o.held,0)')->exists(), 'legacy_main_wallet_holds');
        $check(! (clone $bindings)->where('b.role', 'point')->leftJoinSub($pointHolds, 'o', 'o.budget_wallet_id', '=', 'w.id')->whereRaw('w.held_minor <> COALESCE(o.held,0)')->exists(), 'point_wallet_holds');
        $spent = $db->table('digital_orders')->whereNotNull('topup_grant_id')->whereNotNull('accounted_at')->where('status', 'succeeded')->groupBy('topup_grant_id')->select('topup_grant_id')->selectRaw('SUM(admin_price_minor) AS spent');
        $check(! (clone $grants)->leftJoinSub($spent, 'o', 'o.topup_grant_id', '=', 'g.id')->whereRaw('g.spent_minor <> COALESCE(o.spent,0)')->exists(), 'grant_accounted_spent');
        $events = $db->table('topup_finance_events as f')->join('finance_transactions as t', 't.id', '=', 'f.transaction_id')->join('digital_connections as c', 'c.id', '=', 'f.connection_id')->join('topup_grants as g', 'g.id', '=', 'f.grant_id');
        $check(! (clone $events)->where(fn ($query) => $query->where('t.service', '<>', 'topup')->orWhere('t.currency', '<>', 'IQD')->orWhere('t.posted', '<>', 1)->orWhere('c.provider', '<>', 'topup'))->exists(), 'event_transaction_identity');
        $check(! (clone $events)->whereColumn('c.account_id', '<>', 'g.from_account_id')->exists(), 'event_clearing_owner');
        $check(! $db->table('finance_transactions')->where('service', 'topup')->whereNotIn('id', $db->table('topup_finance_events')->select('transaction_id'))->exists(), 'missing_topup_event');
        $check(! (clone $events)->whereRaw("NOT ((f.phase='opening' AND t.kind='topup-opening') OR (f.phase='allocation' AND t.kind='topup-allocation') OR (f.phase='funding' AND t.kind IN ('transfer','funding')) OR (f.phase='recovery' AND t.kind='recovery') OR (f.phase='sale' AND t.kind='topup-sale') OR (f.phase='refund' AND t.kind='topup-refund'))")->exists(), 'event_phase_identity');
        $check(! (clone $events)->join('finance_entries as e', 'e.transaction_id', '=', 't.id')->whereNotIn('e.wallet_id', $db->table('topup_wallet_bindings')->select('wallet_id'))->exists(), 'unbound_event_leg');
        $check(! (clone $events)->leftJoin('digital_orders as o', 'o.id', '=', 'f.order_id')->whereIn('f.phase', ['sale', 'refund'])->where(fn ($query) => $query->whereNull('o.id')->orWhereColumn('o.topup_grant_id', '<>', 'f.grant_id')->orWhereColumn('o.connection_id', '<>', 'f.connection_id')
            ->orWhere(fn ($status) => $status->where('f.phase', 'refund')->where('o.status', '<>', 'refunded'))
            ->orWhere(fn ($status) => $status->where('f.phase', 'sale')->whereNotIn('o.status', ['succeeded', 'refunded'])))->exists(), 'order_event_identity');
        $check(! $db->table('digital_orders as o')->where('o.budget_basis', 'point_budget_v1')->whereNotNull('o.accounted_at')->whereIn('o.status', ['succeeded', 'refunded'])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('topup_finance_events as f')->whereColumn('f.order_id', 'o.id')->where('f.phase', 'sale'))->exists(), 'missing_point_sale_posting');
        $check(! $db->table('digital_orders as o')->where('o.budget_basis', 'point_budget_v1')->where('o.status', 'refunded')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('topup_finance_events as f')->whereColumn('f.order_id', 'o.id')->where('f.phase', 'refund'))->exists(), 'missing_point_refund_posting');
        $legs = $db->table('finance_entries')->selectRaw('transaction_id, COUNT(*) AS legs, COUNT(DISTINCT wallet_id) AS wallets, SUM(amount_minor) AS total')->groupBy('transaction_id');
        $saleLegs = (clone $events)->join('digital_orders as o', 'o.id', '=', 'f.order_id')->join('finance_entries as e', 'e.transaction_id', '=', 't.id')->join('topup_wallet_bindings as b', 'b.wallet_id', '=', 'e.wallet_id')->join('finance_wallets as w', 'w.id', '=', 'e.wallet_id')->whereIn('f.phase', ['sale', 'refund']);
        $direction = "CASE WHEN f.phase='refund' THEN -1 ELSE 1 END";
        $point = "o.budget_basis='point_budget_v1'";
        $admin = 'CAST(o.admin_price_minor AS SIGNED)';
        $quotedRetail = 'CAST(o.quoted_retail_minor AS SIGNED)';
        $retail = "CASE WHEN {$point} THEN {$quotedRetail} ELSE {$admin} END";
        $validLeg = "((b.role='clearing' AND b.grant_id IS NULL AND w.account_id=c.account_id AND e.amount_minor=({$direction})*({$admin})) OR (b.grant_id=f.grant_id AND (({$point} AND b.role='point' AND w.id=o.budget_wallet_id AND w.account_id=o.account_id AND e.amount_minor=-({$direction})*({$retail})) OR (o.budget_basis='legacy_main' AND b.role='main' AND w.account_id=o.main_account_id AND e.amount_minor=-({$direction})*({$admin})) OR ({$point} AND b.role='margin' AND w.account_id=o.main_account_id AND {$quotedRetail}>{$admin} AND e.amount_minor=({$direction})*({$quotedRetail}-{$admin})))))";
        $check(! (clone $saleLegs)->whereRaw('COALESCE(('.$validLeg.'),0)=0')->exists(), 'order_posting_amounts');
        $check(! (clone $events)->join('digital_orders as o', 'o.id', '=', 'f.order_id')->joinSub($legs, 'l', 'l.transaction_id', '=', 't.id')->whereIn('f.phase', ['sale', 'refund'])
            ->whereRaw("(l.legs <> CASE WHEN {$point} AND {$quotedRetail}>{$admin} THEN 3 ELSE 2 END OR l.wallets <> l.legs OR l.total <> 0)")->exists(), 'order_posting_legs');
        $movementLegs = (clone $events)->join('finance_entries as e', 'e.transaction_id', '=', 't.id')->join('topup_wallet_bindings as b', 'b.wallet_id', '=', 'e.wallet_id')->join('finance_wallets as w', 'w.id', '=', 'e.wallet_id')->whereIn('f.phase', ['opening', 'allocation', 'funding', 'recovery']);
        $mainLeg = "b.grant_id=f.grant_id AND b.role='main' AND w.account_id=g.target_account_id";
        $pointLeg = "b.grant_id=f.grant_id AND b.role='point' AND EXISTS (SELECT 1 FROM account_closure a WHERE a.ancestor_id=g.target_account_id AND a.descendant_id=w.account_id AND a.depth>0)";
        $clearingLeg = "b.grant_id IS NULL AND b.role='clearing' AND w.account_id=c.account_id";
        $validMovement = "((f.phase='opening' AND (({$mainLeg} AND e.amount_minor>0) OR ({$clearingLeg} AND e.amount_minor<0))) OR (f.phase='allocation' AND (({$mainLeg}) OR ({$clearingLeg}))) OR (f.phase='funding' AND (({$mainLeg} AND e.amount_minor<0) OR ({$pointLeg} AND e.amount_minor>0))) OR (f.phase='recovery' AND (({$mainLeg} AND e.amount_minor>0) OR ({$pointLeg} AND e.amount_minor<0))))";
        $check(! (clone $movementLegs)->whereRaw('NOT ('.$validMovement.')')->exists(), 'movement_posting_grant_direction');
        $check(! (clone $events)->joinSub($legs, 'l', 'l.transaction_id', '=', 't.id')->whereIn('f.phase', ['opening', 'allocation', 'funding', 'recovery'])->whereRaw('(l.legs <> 2 OR l.wallets <> 2 OR l.total <> 0)')->exists(), 'movement_posting_legs');

        return ['ok' => $issues === [], 'issues' => $issues, 'read_only' => true];
    }
}
