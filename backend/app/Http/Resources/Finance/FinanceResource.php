<?php

namespace App\Http\Resources\Finance;

use App\Enums\AccountType;
use App\Models\Finance\FundingRecovery;
use App\Models\Finance\FundingRequest;
use App\Models\Finance\Invoice;
use App\Models\Finance\LedgerTransaction;
use App\Models\Finance\Policy;
use App\Models\Finance\PriceRequest;
use App\Models\Finance\Wallet;
use App\Services\AccountPrivacy;
use App\Services\Finance\FinanceServiceIdentity;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacy = app(AccountPrivacy::class);
        $r = $this->resource;
        $service = $r instanceof FundingRecovery ? $r->transaction?->service : ($r->getAttributes()['service'] ?? null);
        $serviceMetadata = app(FinanceServiceIdentity::class)->metadata($service);
        if ($r instanceof FundingRecovery) {
            return $serviceMetadata + ['id' => $r->id, 'transfer_id' => (int) $r->transfer_id, 'transaction_id' => (int) $r->transaction_id, 'service' => $r->transaction?->service, 'currency' => $r->transaction?->currency, 'from_account_id' => (int) $r->from_account_id, 'to_account_id' => (int) $r->to_account_id, 'from_account_name' => $privacy->accountName($r->fromAccount), 'to_account_name' => $privacy->accountName($r->toAccount), 'actor_id' => $privacy->userId($r->actor_id), 'actor_name' => $privacy->userName($r->actor), 'amount' => Money::decimal($r->amount_minor), 'reason' => $r->reason, 'created_at' => $r->created_at->toISOString()];
        }
        if ($r instanceof Wallet) {
            return $serviceMetadata + ['id' => $r->id, 'account_id' => (int) $r->account_id, 'account_name' => $privacy->accountName($r->account), 'service' => $r->service, 'currency' => $r->currency, 'balance' => Money::decimal($r->balance_minor), 'held' => Money::decimal($r->held_minor), 'available' => ($serviceMetadata['legacy_service'] ?? false) ? '0.00' : Money::decimal($r->balance_minor - $r->held_minor), 'version' => $r->version];
        }
        if ($r instanceof Policy) {
            return ['version' => $r->version, 'daily_limit' => $r->daily_limit, 'amounts' => array_map(Money::decimal(...), $r->amounts_minor), 'recovery_hours' => $r->recovery_hours];
        }
        if ($r instanceof LedgerTransaction) {
            return $serviceMetadata + ['id' => $r->id, 'kind' => $r->kind, 'service' => $r->service, 'currency' => $r->currency, 'reference' => $r->reference, 'reversal_of' => $r->reversal_of, 'recovery_deadline' => $r->recovery_deadline?->toISOString(), 'created_at' => $r->created_at->toISOString()];
        }
        $base = ['id' => $r->id, 'account_id' => (int) ($r->account_id ?? $r->to_account_id), 'creator_id' => $privacy->userId($r->creator_id), 'status' => $r->status, 'version' => $r->version, 'created_at' => $r->created_at?->toISOString(), 'updated_at' => $r->updated_at?->toISOString()];
        $base += ['creator_name' => $privacy->userName($r->creator)];
        if ($r instanceof FundingRequest) {
            $base += ['account_name' => $privacy->accountName($r->toAccount), 'from_account_name' => $privacy->accountName($r->fromAccount), 'to_account_name' => $privacy->accountName($r->toAccount), 'reviewer_name' => $privacy->userName($r->reviewer)];
        } else {
            $base += ['account_name' => $privacy->accountName($r->account)];
        }
        if ($r instanceof PriceRequest) {
            $base += ['reviewer_name' => $privacy->userName($r->reviewer)];
        }
        if ($r instanceof Invoice) {
            $base['creditor_account_id'] = $r->creditor_account_id;
            if ($r->source_type === 'digital_topup' && $r->digitalOrder) {
                $base['quantity'] = 1;
                if ($request->user()->membership->account->type !== AccountType::Pos && in_array('data.cost', $request->user()->membership->permissions(), true)) {
                    $base['load_price'] = Money::decimal($r->digitalOrder->admin_price_minor);
                }
                if ($request->user()->membership->account->type !== AccountType::Pos && in_array('data.profit', $request->user()->membership->permissions(), true)) {
                    $base['profit'] = Money::decimal($r->digitalOrder->quoted_retail_minor - $r->digitalOrder->admin_price_minor);
                }
            }

            return $serviceMetadata + $base + ['kind' => $r->kind, 'supplier' => $r->supplier, 'service' => $r->service, 'currency' => $r->currency, 'amount' => Money::decimal($r->amount_minor), 'paid' => Money::decimal($r->paid_minor), 'remaining' => Money::decimal($r->amount_minor - $r->paid_minor), 'reference' => $r->reference, 'source_type' => $r->source_type, 'source_id' => $r->source_id];
        }
        if ($r instanceof FundingRequest) {
            return $serviceMetadata + $base + ['from_account_id' => (int) $r->from_account_id, 'to_account_id' => (int) $r->to_account_id, 'service' => $r->service, 'currency' => $r->currency, 'amount' => Money::decimal($r->amount_minor), 'purpose' => $r->purpose, 'reason' => $r->reason, 'reference' => $r->reference, 'transaction_id' => $r->transaction_id, 'stock_batch_id' => $r->stock_batch_id, 'reviewer_id' => $privacy->userId($r->reviewer_id), 'reviewed_at' => $r->reviewed_at?->toISOString()];
        }
        if ($r instanceof PriceRequest) {
            return $base + ['changes' => array_map(fn (array $c): array => ['product_id' => (int) $c['product_id'], 'name' => $c['name'], 'currency' => $c['currency'], 'old_price' => $c['old_minor'] === null ? null : Money::decimal($c['old_minor']), 'price' => Money::decimal($c['price_minor'])], $r->changes), 'reason' => $r->reason, 'reviewer_id' => $privacy->userId($r->reviewer_id), 'reviewed_at' => $r->reviewed_at?->toISOString()];
        }

        return [];
    }
}
