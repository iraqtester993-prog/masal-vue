<?php

namespace App\Http\Resources\Digital;

use App\Enums\AccountType;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DigitalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'connection_id' => $this->connection_id, 'offer_id' => $this->offer_id, 'account_id' => $this->account_id, 'account_name' => $this->account?->name, 'main_account_id' => $this->main_account_id, 'main_account_name' => $this->mainAccount?->name, 'creator_id' => $this->creator_id, 'creator_name' => $this->creator?->name, 'product_id' => $this->product_id, 'product_name' => $this->product?->name, 'provider' => $this->provider, 'currency' => 'IQD', 'request_id' => $this->request_id, 'mobile' => $this->mobile, 'package_type' => $this->package_type, 'status' => $this->status, 'reservation_active' => $this->reservation_active, 'retail' => Money::decimal($this->actual_retail_minor ?? $this->quoted_retail_minor), 'quoted_retail' => Money::decimal($this->quoted_retail_minor), 'company_transaction_id' => $this->company_transaction_id, 'message' => $this->message, 'version' => $this->version, 'created_at' => $this->created_at->toISOString(), 'resolved_at' => $this->resolved_at?->toISOString(), 'refunded_at' => $this->refunded_at?->toISOString()];
        $actor = $request->user();
        if ($this->admin_price_minor !== null && $actor->membership->account->type !== AccountType::Pos) {
            $data += ['admin_price' => Money::decimal((int) $this->admin_price_minor), 'agent_markup' => Money::decimal($this->quoted_retail_minor - (int) $this->admin_price_minor), 'accounted' => $this->accounted_at !== null];
        }
        $direct = $this->provider === 'topup' && config('digital.topup.driver') === 'masal_v2_1';
        $data += ['manual_category_name' => $this->manual_category_name, 'verification_supported' => ! $direct || in_array($this->status, ['pending', 'review'], true) && $this->getRawOriginal('provider_purchase_response') !== null, 'refund_verification_supported' => ! $direct];
        $data += ['company_purchase_confirmed' => $this->getRawOriginal('provider_evidence') !== null, 'receipt_available' => $this->status === 'succeeded' && $this->getRawOriginal('receipt') !== null, 'receipt_missing' => $this->provider === 'rabiaa' && $this->getRawOriginal('provider_evidence') !== null && $this->getRawOriginal('receipt') === null];
        if ($actor->membership->account->type !== AccountType::Pos && in_array('data.cost', $actor->membership->permissions(), true)) {
            $data += ['cost' => $this->actual_cost_minor === null ? null : Money::decimal($this->actual_cost_minor), 'quoted_cost' => Money::decimal($this->quoted_cost_minor), 'cost_basis' => $this->cost_basis];
        }

        return $data;
    }
}
