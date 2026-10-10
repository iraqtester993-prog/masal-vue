<?php

namespace App\Http\Resources\Stock;

use App\Models\Stock\StockAdjustment;
use App\Models\Stock\StockBatch;
use App\Models\Stock\StockCard;
use App\Models\Stock\StockClaim;
use App\Models\Stock\StockOrder;
use App\Models\Stock\StockWithdrawal;
use App\Services\Finance\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissions = $request->user()->membership->permissions();
        $costs = in_array('data.cost', $permissions, true);
        $secrets = in_array('data.pin', $permissions, true);
        $common = ['id' => $this->id, 'account_id' => (int) $this->account_id, 'status' => $this->status, 'version' => (int) $this->version, 'created_at' => $this->created_at?->toISOString()];
        if ($this->resource instanceof StockOrder) {
            $summary = $this->summary;
            foreach ($summary['lines'] as &$line) {
                $line['batch_id'] = $this->resource->relationLoaded('batches') ? $this->batches->firstWhere('line_key', $line['key'])?->id : null;
                if ($request->route()->getActionMethod() !== 'showOrder') {
                    unset($line['checked']);
                }
            }
            unset($line);
            if (! $costs) {
                unset($summary['amounts']);
                foreach ($summary['lines'] as &$line) {
                    unset($line['cost'], $line['expenses'], $line['load_price'], $line['cost_total'], $line['amount']);
                }
                unset($line);
            }
            $result = $common + ['account_name' => $this->account?->name, 'provider_id' => (int) $this->provider_id, 'provider_name' => $this->provider?->name, 'source_id' => (int) $this->source_id, 'source_name' => $this->source?->name, 'city' => $this->city, 'quantity' => (int) $this->quantity, 'rejected' => (int) $this->rejected, 'creator_id' => (int) $this->creator_id, 'reason' => $this->reason, 'reviewed_at' => $this->reviewed_at?->toISOString(), 'summary' => $summary, 'batch_ids' => $this->whenLoaded('batches', fn () => $this->batches->pluck('id')->all())];
            if ($request->route()->getActionMethod() === 'showOrder') {
                $draft = $this->payload;
                if (! $costs) {
                    foreach ($draft['lines'] as &$line) {
                        unset($line['cost'], $line['expenses']);
                    }
                    unset($line);
                }
                if (! $secrets) {
                    foreach ($draft['lines'] as &$line) {
                        foreach ($line['rows'] as &$row) {
                            unset($row['pin'], $row['cvc'], $row['reference'], $row['extra_fields']);
                        }
                        unset($row);
                    }
                    unset($line);
                }
                $result['draft'] = $draft;
                $events = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                    ->where('subject_account_id', $this->account_id)->whereIn('action', ['import.submit', 'import.resubmit', 'import.approve'])
                    ->where('details->order_id', $this->id)->orderByDesc('audit_logs.id')->limit(1001)
                    ->get(['audit_logs.id', 'action', 'user_id', 'users.name as user_name', 'audit_logs.created_at', 'details']);
                $result['events_truncated'] = $events->count() > 1000;
                $result['events'] = $events->take(1000)->reverse()->values()->map(function ($event): array {
                    $details = json_decode($event->details, true, 512, JSON_THROW_ON_ERROR);
                    $decision = $details['decision'] ?? null;

                    return ['id' => (int) $event->id, 'action' => $event->action, 'decision' => $decision, 'label' => match ($event->action) {
                        'import.submit' => 'إرسال', 'import.resubmit' => 'إعادة إرسال', default => match ($decision) {
                            'approve' => 'اعتماد', 'return' => 'إعادة للتصحيح', default => 'رفض'
                        }
                    }, 'user_id' => $event->user_id, 'user_name' => $event->user_name, 'time' => CarbonImmutable::parse($event->created_at, 'UTC')->toISOString(), 'reason' => $details['reason'] ?? ''];
                })->all();
            }

            return $result;
        }
        if ($this->resource instanceof StockBatch) {
            $result = $common + ['order_id' => $this->order_id, 'account_name' => $this->account?->name, 'product_id' => (int) $this->product_id, 'product_name' => $this->product?->name, 'provider_id' => $this->product?->provider_id, 'source_id' => (int) $this->source_id, 'supplier' => $this->supplier, 'file_name' => $this->file_name, 'city' => $this->city, 'notes' => $this->notes, 'currency' => $this->currency, 'quantity' => (int) $this->quantity, 'rejected' => (int) $this->rejected, 'available_count' => (int) ($this->available_count ?? 0), 'sold_count' => (int) ($this->sold_count ?? 0), 'damaged_count' => (int) ($this->damaged_count ?? 0), 'cancelled_count' => (int) ($this->cancelled_count ?? 0), 'exported_count' => (int) ($this->exported_count ?? 0), 'reserved_count' => (int) ($this->reserved_count ?? 0), 'replacement_claim_id' => $this->replacement_claim_id];
            $result += ['min_expiry' => $this->cards_min_expiry, 'max_expiry' => $this->cards_max_expiry];
            if ($costs) {
                $result += ['cost' => Money::decimal($this->cost_minor), 'expenses' => Money::decimal($this->expenses_minor), 'cost_total' => Money::decimal($this->cost_total_minor), 'load_price' => Money::decimal($this->load_price_minor), 'amount' => Money::decimal($this->amount_minor), 'profit' => Money::decimal($this->amount_minor - $this->cost_total_minor), 'invoice_id' => $this->invoice_id, 'transaction_id' => $this->transaction_id];
            }

            return $result;
        }
        if ($this->resource instanceof StockCard) {
            $result = $common + ['batch_id' => (int) $this->batch_id, 'product_id' => (int) $this->product_id, 'serial' => $this->serial, 'expiry' => $this->expiry->format('Y-m-d'), 'credit_held' => $this->credit_held, 'sale_id' => $this->sale_id, 'claim_id' => $this->claim_id, 'adjustment_id' => $this->adjustment_id];
            if ($secrets) {
                $result += $this->secret;
            }
            if ($costs) {
                $result += ['cost' => Money::decimal($this->cost_minor), 'credit' => Money::decimal($this->credit_minor)];
            }

            return $result;
        }
        if ($this->resource instanceof StockClaim || $this->resource instanceof StockAdjustment || $this->resource instanceof StockWithdrawal) {
            $result = $common + ['batch_id' => (int) $this->batch_id, 'card_ids' => $this->card_ids, 'creator_id' => (int) $this->creator_id, 'reason' => $this->reason, 'quantity' => count($this->card_ids), 'product_id' => $this->batch?->product_id, 'product_name' => $this->batch?->product?->name];
            $result['account_name'] = $this->batch?->account?->name;
            $result['currency'] = $this->batch?->currency;
            if ($this->resource instanceof StockWithdrawal) {
                $result += ['valid_until' => $this->valid_until?->toISOString(), 'downloaded_at' => $this->downloaded_at?->toISOString(), 'claim_id' => $this->claim_id, 'review_reason' => $this->review_reason];
                if ($costs) {
                    $result['debit'] = Money::decimal($this->debit_minor);
                }
            } elseif ($this->resource instanceof StockClaim) {
                $result += ['reviewed_at' => $this->reviewed_at?->toISOString(), 'replacement_batch_id' => $this->replacement_batch_id];
            } else {
                $result += ['kind' => $this->kind, 'restored_at' => $this->restored_at?->toISOString()];
            }
            if ($costs && ! $this->resource instanceof StockWithdrawal) {
                $result += ['credit' => Money::decimal($this->credit_minor), 'cost' => Money::decimal($this->cost_minor)];
                if ($this->resource instanceof StockClaim) {
                    $result['compensation_amount'] = Money::decimal(Money::multiply($this->batch->load_price_minor, $this->quantity));
                }
            }

            return $result;
        }

        return $common;
    }
}
