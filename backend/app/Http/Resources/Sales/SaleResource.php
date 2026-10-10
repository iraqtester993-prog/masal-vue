<?php

namespace App\Http\Resources\Sales;

use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sale = $this->resource;
        $data = ['id' => $sale->id, 'account_id' => (int) $sale->account_id, 'account_name' => $sale->account?->name, 'main_account_id' => (int) $sale->main_account_id, 'creator_id' => (int) $sale->creator_id, 'creator_name' => $sale->creator?->name, 'product_id' => (int) $sale->product_id, 'product_name' => $sale->product?->name, 'provider_id' => (int) $sale->provider_id, 'currency' => $sale->currency, 'quantity' => $sale->quantity, 'price' => Money::decimal($sale->price_minor), 'total' => Money::decimal($sale->total_minor), 'retail_price' => Money::decimal($sale->retail_price_minor), 'retail_total' => Money::decimal($sale->retail_total_minor), 'credit' => Money::decimal($sale->credit_minor), 'pos_profit' => Money::decimal($sale->retail_total_minor - $sale->total_minor), 'status' => $sale->status, 'version' => $sale->version, 'price_version' => $sale->price_version, 'transaction_id' => $sale->transaction_id, 'print_pending' => $sale->print_pending, 'failure_retry' => $sale->failure_retry, 'failed_retry_count' => $sale->failed_retry_count, 'reprints' => $sale->reprints, 'reprint_request_id' => $sale->reprint_request_id, 'failure_reason' => $sale->failure_reason, 'delivery_channel' => $sale->delivery_channel, 'delivery_reference' => $sale->delivery_reference, 'created_at' => $sale->created_at->toISOString(), 'issued_at' => $sale->issued_at?->toISOString(), 'exposed_at' => $sale->exposed_at?->toISOString(), 'print_started_at' => $sale->print_started_at?->toISOString(), 'first_printed_at' => $sale->first_printed_at?->toISOString()];
        $permissions = $request->user()->membership->permissions();
        $data['main_account_name'] = $sale->mainAccount?->name;
        $data['provider_name'] = $sale->provider?->name;
        unset($data['pos_profit']);
        if (in_array('data.profit', $permissions, true)) {
            $data['pos_profit'] = Money::decimal($sale->retail_total_minor - $sale->total_minor);
        }
        if (in_array('data.cost', $permissions, true)) {
            $data += ['cost' => Money::decimal($sale->cost_minor), 'load_cost' => Money::decimal($sale->load_cost_minor)];
            if (in_array('data.profit', $permissions, true)) {
                $data['agent_margin'] = Money::decimal($sale->credit_minor - $sale->load_cost_minor);
            }
        }
        if ($sale->relationLoaded('attempts')) {
            $data['attempts'] = $sale->attempts->map(fn ($a): array => ['id' => $a->id, 'actor_id' => (int) $a->actor_id, 'kind' => $a->kind, 'status' => $a->status, 'reason' => $a->reason, 'started_at' => $a->started_at->toISOString(), 'finished_at' => $a->finished_at?->toISOString(), 'version' => $a->version])->all();
        }

        return $data;
    }
}
