<?php

namespace App\Http\Resources\Finance;

use App\Models\Finance\FundingRecovery;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Policy;
use App\Models\Finance\PriceRequest;
use App\Models\Finance\Wallet;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        if ($r instanceof FundingRecovery) {
            return ['id' => $r->id, 'transfer_id' => (int) $r->transfer_id, 'transaction_id' => (int) $r->transaction_id, 'service' => $r->transaction?->service, 'currency' => $r->transaction?->currency, 'from_account_id' => (int) $r->from_account_id, 'to_account_id' => (int) $r->to_account_id, 'from_account_name' => $r->fromAccount?->name, 'to_account_name' => $r->toAccount?->name, 'actor_id' => (int) $r->actor_id, 'actor_name' => $r->actor?->name, 'amount' => Money::decimal($r->amount_minor), 'reason' => $r->reason, 'created_at' => $r->created_at->toISOString()];
        }
        if ($r instanceof Wallet) {
            return ['id' => $r->id, 'account_id' => (int) $r->account_id, 'account_name' => $r->account?->name, 'service' => $r->service, 'currency' => $r->currency, 'balance' => Money::decimal($r->balance_minor), 'held' => Money::decimal($r->held_minor), 'available' => Money::decimal($r->balance_minor - $r->held_minor), 'version' => $r->version];
        }
        if ($r instanceof Policy) {
            return ['version' => $r->version, 'daily_limit' => $r->daily_limit, 'amounts' => array_map(Money::decimal(...), $r->amounts_minor), 'recovery_hours' => $r->recovery_hours];
        }
        if ($r instanceof LedgerTransaction) {
            return ['id' => $r->id, 'kind' => $r->kind, 'service' => $r->service, 'currency' => $r->currency, 'reference' => $r->reference, 'reversal_of' => $r->reversal_of, 'recovery_deadline' => $r->recovery_deadline?->toISOString(), 'created_at' => $r->created_at->toISOString()];
        }
        $base = ['id' => $r->id, 'account_id' => (int) ($r->account_id ?? $r->to_account_id), 'creator_id' => (int) $r->creator_id, 'status' => $r->status, 'version' => $r->version, 'created_at' => $r->created_at?->toISOString(), 'updated_at' => $r->updated_at?->toISOString()];
        $base += ['creator_name' => $r->creator?->name];
        if ($r instanceof FundingRequest) {
            $base += ['account_name' => $r->toAccount?->name, 'from_account_name' => $r->fromAccount?->name, 'to_account_name' => $r->toAccount?->name, 'reviewer_name' => $r->reviewer?->name];
        } else {
            $base += ['account_name' => $r->account?->name];
        }
        if ($r instanceof PriceRequest) {
            $base += ['reviewer_name' => $r->reviewer?->name];
        }
        if ($r instanceof Invoice) {
            $base['creditor_account_id'] = $r->creditor_account_id;
            return $base + ['kind' => $r->kind, 'supplier' => $r->supplier, 'service' => $r->service, 'currency' => $r->currency, 'amount' => Money::decimal($r->amount_minor), 'paid' => Money::decimal($r->paid_minor), 'remaining' => Money::decimal($r->amount_minor - $r->paid_minor), 'reference' => $r->reference, 'source_type' => $r->source_type, 'source_id' => $r->source_id];
        }
        if ($r instanceof FundingRequest) {
            return $base + ['from_account_id' => (int) $r->from_account_id, 'to_account_id' => (int) $r->to_account_id, 'service' => $r->service, 'currency' => $r->currency, 'amount' => Money::decimal($r->amount_minor), 'purpose' => $r->purpose, 'reason' => $r->reason, 'reference' => $r->reference, 'transaction_id' => $r->transaction_id, 'stock_batch_id' => $r->stock_batch_id, 'reviewer_id' => $r->reviewer_id, 'reviewed_at' => $r->reviewed_at?->toISOString()];
        }
        if ($r instanceof PriceRequest) {
            return $base + ['changes' => array_map(fn (array $c): array => ['product_id' => (int) $c['product_id'], 'name' => $c['name'], 'currency' => $c['currency'], 'old_price' => $c['old_minor'] === null ? null : Money::decimal($c['old_minor']), 'price' => Money::decimal($c['price_minor'])], $r->changes), 'reason' => $r->reason, 'reviewer_id' => $r->reviewer_id, 'reviewed_at' => $r->reviewed_at?->toISOString()];
        }

        return [];
    }
}
