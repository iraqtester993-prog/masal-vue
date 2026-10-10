<?php

namespace App\Services\Finance;

use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Wallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Ledger
{
    public function wallet(int $accountId, string $service, string $currency, string $kind = 'account'): Wallet
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        DB::table('finance_wallets')->insertOrIgnore(['account_id' => $accountId, 'service' => $service, 'currency' => $currency, 'kind' => $kind, 'balance_minor' => 0, 'held_minor' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return Wallet::where(compact('service', 'currency', 'kind'))->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<array{wallet:Wallet,amount:int}> $legs */
    public function post(User $actor, string $kind, string $key, array $payload, string $service, string $currency, string $reference, array $legs, ?int $reversalOf = null): LedgerTransaction
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $hash = self::hash([$kind, $payload]);
        $old = LedgerTransaction::where('actor_id', $actor->id)->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($old) {
            abort_unless($old->payload_hash === $hash && $old->posted, 409, 'معرف العملية مستخدم لبيانات مختلفة.');

            return $old;
        }
        if (count($legs) < 2 || count(array_unique(array_map(fn (array $leg): int => $leg['wallet']->id, $legs))) !== count($legs) || array_sum(array_column($legs, 'amount')) !== 0) {
            throw new \LogicException('Financial posting must balance across distinct wallets.');
        }
        usort($legs, fn (array $a, array $b): int => $a['wallet']->id <=> $b['wallet']->id);
        foreach ($legs as &$leg) {
            $wallet = Wallet::whereKey($leg['wallet']->id)->lockForUpdate()->firstOrFail();
            $leg['wallet'] = $wallet;
            if ($wallet->service !== $service || $wallet->currency !== $currency || ! is_int($leg['amount']) || $leg['amount'] === 0) {
                throw new \LogicException('Invalid posting service, currency or amount.');
            }
            $balance = $wallet->balance_minor + $leg['amount'];
            if (abs($balance) > Money::MAX_MINOR || ($wallet->kind === 'account' && $balance < $wallet->held_minor)) {
                throw ValidationException::withMessages(['amount' => 'الرصيد المتاح لا يكفي للعملية أو يتجاوز الحد المسموح.']);
            }
        }
        unset($leg);
        $deadline = in_array($kind, ['transfer', 'funding'], true) ? now()->addHours((int) DB::table('finance_policy')->where('id', 1)->value('recovery_hours')) : null;
        $transaction = LedgerTransaction::create(['actor_id' => $actor->id, 'kind' => $kind, 'service' => $service, 'currency' => $currency, 'reference' => $reference, 'idempotency_key' => $key, 'payload_hash' => $hash, 'posted' => false, 'reversal_of' => $reversalOf, 'recovery_deadline' => $deadline, 'created_at' => now()]);
        foreach ($legs as $leg) {
            $wallet = $leg['wallet'];
            $after = $wallet->balance_minor + $leg['amount'];
            DB::table('finance_entries')->insert(['transaction_id' => $transaction->id, 'wallet_id' => $wallet->id, 'amount_minor' => $leg['amount'], 'balance_after_minor' => $after, 'created_at' => now()]);
            $wallet->update(['balance_minor' => $after, 'version' => $wallet->version + 1]);
        }
        $transaction->update(['posted' => true]);

        return $transaction;
    }

    public function pair(User $actor, string $kind, string $key, array $payload, Wallet $debit, Wallet $credit, int $amount, string $reference, ?int $reversalOf = null): LedgerTransaction
    {
        if ($amount < 1 || $amount > Money::MAX_MINOR) {
            throw new \LogicException('Financial amount must be a positive bounded integer.');
        }

        return $this->post($actor, $kind, $key, $payload, $debit->service, $debit->currency, $reference, [['wallet' => $debit, 'amount' => -$amount], ['wallet' => $credit, 'amount' => $amount]], $reversalOf);
    }
}
