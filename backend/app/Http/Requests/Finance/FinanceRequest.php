<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $amount = ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D'];
        $key = ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9:_\-.]+\z/D'];
        $identity = ['account_id' => ['required', 'integer', 'min:1']];
        $money = ['service' => ['required', 'string', 'max:40'], 'currency' => ['required', Rule::in(['IQD'])], 'amount' => $amount];
        $posting = ['reference' => ['required', 'string', 'max:200'], 'idempotency_key' => $key];
        $cas = ['version' => ['required', 'integer', 'min:1'], 'idempotency_key' => $key];
        $read = ['account_id' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'service' => ['sometimes', 'string', 'max:40'], 'currency' => ['sometimes', Rule::in(['IQD'])], 'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled', 'reversed', 'unpaid', 'partial', 'paid'])]];
        $read += ['q' => ['sometimes', 'nullable', 'string', 'max:200'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'], 'account_ids' => ['sometimes', 'array', 'max:10000'], 'account_ids.*' => ['integer', 'min:1', 'distinct']];
        $read += ['direction' => ['sometimes', Rule::in(['incoming', 'outgoing'])]];
        $read += ['recoverable' => ['sometimes', 'boolean'], 'provider_id' => ['sometimes', 'integer', 'min:1'], 'product_id' => ['sometimes', 'integer', 'min:1']];
        $read += ['price_state' => ['sometimes', Rule::in(['active', 'inactive', 'priced', 'unpriced'])], 'kind' => ['sometimes', Rule::in(['receivable', 'payable'])]];
        $read['to'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        $read['from'] = ['sometimes', 'nullable', 'date_format:Y-m-d'];
        if ($this->filled('from')) {
            $read['to'][] = 'after_or_equal:from';
        }

        return match ($this->route()->getActionMethod()) {
            'deposits' => $identity + $money + $posting,
            'transfers' => ['from_account_id' => ['required', 'integer', 'min:1'], 'to_account_id' => ['required', 'integer', 'min:1', 'different:from_account_id']] + $money + $posting,
            'bulkTransfers' => ['from_account_id' => ['sometimes', 'integer', 'min:1'], 'service' => $money['service'], 'currency' => $money['currency'], 'rows' => ['required', 'array', 'min:1', 'max:100'], 'rows.*' => ['required', 'array:to_account_id,amount,service,currency,reference'], 'rows.*.to_account_id' => ['required', 'integer', 'min:1'], 'rows.*.amount' => $amount, 'rows.*.service' => ['sometimes', 'string', 'max:40'], 'rows.*.currency' => ['sometimes', Rule::in(['IQD'])], 'rows.*.reference' => ['sometimes', 'nullable', 'string', 'max:200']] + $posting,
            'recoverTransfer' => ['amount' => $amount, 'reason' => ['required', 'string', 'max:1000'], 'idempotency_key' => $key],
            'storeFundingRequest' => $money + ['purpose' => ['sometimes', 'nullable', 'string', 'max:1000'], 'idempotency_key' => $key],
            'reviewFundingRequest' => $cas + ['decision' => ['required', Rule::in(['approve', 'reject'])], 'reference' => ['required_if:decision,approve', 'string', 'max:200'], 'reason' => ['required_if:decision,reject', 'string', 'max:1000'], 'stock_batch_id' => ['sometimes', 'integer', 'min:1']],
            'cancelFundingRequest' => $cas,
            'savePolicy' => ['version' => ['required', 'integer', 'min:1'], 'daily_limit' => ['required', 'integer', 'between:1,100'], 'amounts' => ['required', 'array', 'min:1', 'max:100'], 'amounts.*' => $amount, 'recovery_hours' => ['sometimes', 'integer', 'between:1,720']],
            'storeInvoice' => $identity + $money + $posting + ['kind' => ['required', Rule::in(['receivable', 'payable'])], 'supplier' => ['required_if:kind,payable', 'string', 'max:160']],
            'settleInvoice' => $cas + ['amount' => $amount, 'reference' => ['required', 'string', 'max:200']],
            'storePriceRequest' => $identity + ['source' => ['sometimes', Rule::in(['manual', 'import'])], 'changes' => ['required', 'array', 'min:1', 'max:500'], 'changes.*' => ['required', 'array:product_id,price,expected_price'], 'changes.*.product_id' => ['required', 'integer', 'min:1', 'distinct'], 'changes.*.price' => $amount, 'changes.*.expected_price' => ['present', 'nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D'], 'idempotency_key' => $key],
            'reviewPriceRequest' => $cas + ['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required_if:decision,reject', 'string', 'max:1000']],
            'reversePriceRequest' => $cas + ['reason' => ['required', 'string', 'max:1000']],
            'options','policy' => [],
            default => $read,
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $unknown = array_diff(array_keys($this->all()), array_keys($this->rules()));
            if ($unknown) {
                $validator->errors()->add('payload', 'حقول غير مسموحة: '.implode(', ', $unknown));
            }
        });
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'required_if' => 'هذا الحقل مطلوب لهذا الإجراء.', 'string' => 'أدخل قيمة نصية صحيحة.', 'regex' => 'أدخل قيمة صحيحة دون رموز أو منازل عشرية زائدة.'];
    }
}
