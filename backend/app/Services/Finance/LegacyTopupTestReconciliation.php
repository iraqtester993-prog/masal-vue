<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Wallet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LegacyTopupTestReconciliation
{
    public function reverse(User $actor, int $depositId, int $fundingId, int $expectedMinor, string $key, Request $request): array
    {
        abort_unless($actor->isOperational() && $actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System, 403);
        abort_unless($expectedMinor > 0 && $expectedMinor <= Money::MAX_MINOR && strlen($key) >= 12 && strlen($key) <= 80, 422);

        return DB::transaction(function () use ($actor, $depositId, $fundingId, $expectedMinor, $key, $request): array {
            $guard = app(MutationGuard::class);
            $guard->lock($actor, [], 'wallets.reconcile-test');
            abort_unless($actor->membership->kind === 'owner' && $actor->membership->account->type === AccountType::System, 403);
            $deposit = LedgerTransaction::whereKey($depositId)->lockForUpdate()->firstOrFail();
            $funding = LedgerTransaction::whereKey($fundingId)->lockForUpdate()->firstOrFail();
            abort_unless($deposit->posted && $funding->posted && $deposit->kind === 'deposit' && $funding->kind === 'funding' && $deposit->currency === 'IQD' && $funding->currency === 'IQD' && $deposit->service === $funding->service && app(FinanceServiceIdentity::class)->isLegacy($deposit->service), 409, 'مصدر التسوية لا يطابق التمويل التجريبي السابق.');
            $depositEntries = DB::table('finance_entries')->where('transaction_id', $depositId)->orderBy('amount_minor')->get();
            $fundingEntries = DB::table('finance_entries')->where('transaction_id', $fundingId)->orderBy('amount_minor')->get();
            foreach ([$depositEntries, $fundingEntries] as $entries) {
                abort_unless($entries->count() === 2 && (int) $entries[0]->amount_minor === -$expectedMinor && (int) $entries[1]->amount_minor === $expectedMinor, 409, 'قيمة أو أرجل الأصل تغيرت.');
            }
            abort_unless((int) $depositEntries[1]->wallet_id === (int) $fundingEntries[0]->wallet_id && $deposit->created_at <= $funding->created_at, 409);
            $walletIds = [(int) $depositEntries[0]->wallet_id, (int) $depositEntries[1]->wallet_id, (int) $fundingEntries[1]->wallet_id];
            abort_unless(count(array_unique($walletIds)) === 3, 409);
            $wallets = Wallet::whereIn('id', $walletIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            [$external, $main, $point] = array_map(fn (int $id): Wallet => $wallets->get($id), $walletIds);
            $mainAccount = Account::findOrFail($main->account_id);
            $pointAccount = Account::findOrFail($point->account_id);
            abort_unless($wallets->count() === 3 && $external->kind === 'external' && $external->account_id === $actor->membership->account_id && $main->kind === 'account' && $mainAccount->type === AccountType::MainAgent && $point->kind === 'account' && $pointAccount->type === AccountType::Pos && $pointAccount->parent_id === $main->account_id, 409);
            $guard->lock($actor, [$main->account_id, $point->account_id], 'wallets.reconcile-test');
            abort_if(DB::table('finance_recoveries')->whereIn('transfer_id', [$depositId, $fundingId])->exists(), 409, 'يوجد استرجاع سابق للأصل.');
            $requestRecord = DB::table('finance_funding_requests')->where('transaction_id', $fundingId)->lockForUpdate()->get();
            abort_unless($requestRecord->count() === 1 && $requestRecord[0]->status === 'approved' && $requestRecord[0]->service === $deposit->service && (int) $requestRecord[0]->from_account_id === $main->account_id && (int) $requestRecord[0]->to_account_id === $point->account_id && (int) $requestRecord[0]->amount_minor === $expectedMinor, 409, 'طلب التمويل الأصلي لا يطابق التسوية.');
            $payload = ['deposit_id' => $depositId, 'funding_id' => $fundingId, 'amount_minor' => $expectedMinor, 'source_service' => $deposit->service, 'reason' => 'عكس رصيد تجريبي مؤكد من مالك النظام؛ رصيد Topup الحقيقي محفوظ'];
            $reversals = LedgerTransaction::whereIn('reversal_of', [$depositId, $fundingId])->lockForUpdate()->get();
            if ($reversals->isNotEmpty()) {
                abort_unless($reversals->count() === 2 && $reversals->every(fn ($tx): bool => $tx->actor_id === $actor->id && $tx->posted && $tx->kind === 'reversal' && $tx->payload_hash === Ledger::hash(['reversal', $payload]) && $tx->idempotency_key === $key.':'.$tx->reversal_of), 409, 'يوجد عكس مختلف للأصل.');

                return ['deposit_reversal_id' => (int) $reversals->firstWhere('reversal_of', $depositId)->id, 'funding_reversal_id' => (int) $reversals->firstWhere('reversal_of', $fundingId)->id, 'source_service' => $deposit->service, 'amount' => Money::decimal($expectedMinor), 'status' => 'reconciled'];
            }
            abort_unless($wallets->every(fn ($wallet): bool => $wallet->held_minor === 0) && $external->balance_minor === -$expectedMinor && $main->balance_minor === 0 && $point->balance_minor === $expectedMinor, 409, 'الأرصدة أو الحجوزات تغيرت؛ أوقفت التسوية.');
            abort_if(DB::table('finance_entries')->whereIn('wallet_id', $walletIds)->whereNotIn('transaction_id', [$depositId, $fundingId])->exists()
                || DB::table('finance_transactions')->where('service', $deposit->service)->whereNotIn('id', [$depositId, $fundingId])->exists()
                || Wallet::where('service', $deposit->service)->whereNotIn('id', $walletIds)->where(fn ($q) => $q->where('balance_minor', '<>', 0)->orWhere('held_minor', '<>', 0))->exists()
                || DB::table('finance_invoices')->where('service', $deposit->service)->exists()
                || DB::table('finance_funding_requests')->where('service', $deposit->service)->where('status', 'pending')->exists()
                || DB::table('digital_orders')->where('provider', 'topup')->where(fn ($q) => $q->where('reservation_active', true)->orWhereIn('status', ['pending', 'review']))->exists(), 409, 'يوجد نشاط آخر على المصدر التجريبي؛ أوقفت التسوية.');
            $protected = $this->fundedSnapshot();
            $ledger = app(Ledger::class);
            $fundingReversal = $ledger->pair($actor, 'reversal', $key.':'.$fundingId, $payload, $point, $main, $expectedMinor, 'عكس التمويل التجريبي #'.$fundingId, $fundingId);
            $depositReversal = $ledger->pair($actor, 'reversal', $key.':'.$depositId, $payload, $main, $external, $expectedMinor, 'عكس الرصيد التجريبي #'.$depositId, $depositId);
            abort_unless(Wallet::whereIn('id', $walletIds)->where(fn ($q) => $q->where('balance_minor', '<>', 0)->orWhere('held_minor', '<>', 0))->doesntExist() && hash_equals($protected, $this->fundedSnapshot()), 409, 'لم تتحقق مطابقة التسوية.');
            $result = ['deposit_reversal_id' => $depositReversal->id, 'funding_reversal_id' => $fundingReversal->id, 'source_service' => $deposit->service, 'amount' => Money::decimal($expectedMinor), 'status' => 'reconciled'];
            app(AuditLogger::class)->record('wallets.reconcile-test', $request, $actor, $point->account_id, $payload + $result);

            return $result;
        }, 5);
    }

    private function fundedSnapshot(): string
    {
        $state = [];
        foreach (['topup_grants', 'topup_wallet_bindings', 'topup_finance_events'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $state['wallets'] = DB::table('finance_wallets')->where('service', 'topup')->orderBy('id')->get()->toArray();
        $state['connections'] = DB::table('digital_connections')->where('provider', 'topup')->orderBy('id')->get(['id', 'company_balance_minor', 'balance_updated_at'])->toArray();

        return Ledger::hash($state);
    }
}
