<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SalesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->route()->getActionMethod() === 'saveLayout' && $this->has('payload')) {
            $data = json_decode((string) $this->input('payload'), true);
            if (is_array($data) && ! array_is_list($data)) {
                $this->replace($data);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $amount = ['string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D'];
        $key = ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9:_\-.]+\z/D'];
        $cas = ['version' => ['required', 'integer', 'min:1'], 'idempotency_key' => $key];
        $read = ['q' => ['sometimes', 'nullable', 'string', 'max:200'], 'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'account_id' => ['sometimes', 'integer', 'min:1'], 'account_ids' => ['sometimes', 'array', 'max:10000'], 'account_ids.*' => ['integer', 'min:1', 'distinct'], 'product_id' => ['sometimes', 'integer', 'min:1'], 'provider_id' => ['sometimes', 'integer', 'min:1'], 'currency' => ['sometimes', Rule::in(['IQD'])], 'status' => ['sometimes', Rule::in(['Reserved', 'Cancelled', 'Print Requested', 'Reprint Requested', 'Print Failed', 'Printed', 'Reprinted', 'Delivered', 'pending', 'approved', 'rejected', 'used'])], 'direction' => ['sometimes', Rule::in(['incoming', 'outgoing'])]];
        if ($this->filled('from')) {
            $read['to'][] = 'after_or_equal:from';
        }
        $policy = ['policy' => ['required', 'array:failed_retries,max_cards,interval_seconds,daily_cards,daily_mode,daily_product_mode,daily_products'], 'policy.failed_retries' => ['required', 'integer', 'between:0,100000'], 'policy.max_cards' => ['required', 'integer', 'between:1,100000'], 'policy.interval_seconds' => ['required', 'integer', 'between:0,100000'], 'policy.daily_cards' => ['required', 'integer', 'between:0,1000000'], 'policy.daily_mode' => ['required', Rule::in(['account', 'network'])], 'policy.daily_product_mode' => ['required', Rule::in(['all', 'selected'])], 'policy.daily_products' => ['present', 'array', 'max:10000', 'required_if:policy.daily_product_mode,selected'], 'policy.daily_products.*' => ['integer', 'min:1', 'distinct']];
        $settings = ['settings' => ['sometimes', 'array:sales_enabled,printing_enabled,velocity_seconds,provider_daily,min_app_version,min_os_version,reprint_limit'], 'settings.sales_enabled' => ['sometimes', 'boolean'], 'settings.printing_enabled' => ['sometimes', 'boolean'], 'settings.velocity_seconds' => ['sometimes', 'integer', 'between:0,3600'], 'settings.provider_daily' => ['sometimes', 'array:IQD'], 'settings.provider_daily.IQD' => ['present_with:settings.provider_daily', 'nullable', ...$amount], 'settings.min_app_version' => ['sometimes', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'], 'settings.min_os_version' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'], 'settings.reprint_limit' => ['sometimes', 'integer', 'between:0,100000']];

        return match ($this->route()->getActionMethod()) {
            'create','reserve' => ['product_id' => ['required', 'integer', 'min:1'], 'quantity' => ['required', 'integer', 'between:1,100000'], 'price_version' => ['required', 'integer', 'min:1'], 'expected_price' => ['required', ...$amount], 'retail_price' => ['sometimes', 'nullable', ...$amount], 'idempotency_key' => $key],
            'heartbeat' => ['serial' => ['sometimes', 'nullable', 'string', 'max:190'], 'app_version' => ['required', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D'], 'os_version' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/D']],
            'issue','cancel','start','retry' => $cas,
            'result' => $cas + ['attempt_id' => ['required', 'integer', 'min:1'], 'success' => ['required', 'boolean'], 'reason' => ['required_if:success,false', 'nullable', 'string', 'max:1000']],
            'requestReprint','escalate' => $cas + ['reason' => ['required', 'string', 'max:1000']],
            'review' => $cas + ['decision' => ['required', Rule::in(['approve', 'reject'])], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000']],
            'deliver' => $cas + ['channel' => ['required', Rule::in(['ملف مشفر', 'استلام مباشر'])], 'reference' => ['required', 'string', 'max:200']],
            'savePolicy' => $cas + $policy + $settings,
            'saveRule' => ['version' => ['required', 'integer', 'min:0'], 'idempotency_key' => $key, 'name' => ['required', 'string', 'max:160'], 'active' => ['required', 'boolean'], 'targets' => ['required', 'array', 'min:1', 'max:10000'], 'targets.*' => ['required', 'array:kind,id'], 'targets.*.kind' => ['required', Rule::in(['pos', 'agent', 'tree'])], 'targets.*.id' => ['required', 'integer', 'min:1']] + $policy,
            'saveLimit' => ['account_id' => ['required', 'integer', 'min:1'], 'product_id' => ['required', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'], 'idempotency_key' => $key, 'max_cards' => ['present', 'nullable', 'integer', 'between:1,100000'], 'daily_quantity' => ['present', 'nullable', 'integer', 'between:1,1000000'], 'daily_amount' => ['present', 'nullable', ...$amount]],
            'saveLayout' => ['account_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'version' => ['required', 'integer', 'min:0'], 'idempotency_key' => $key, 'image' => ['sometimes', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:700', 'dimensions:max_width=4096,max_height=4096'], 'layout' => ['required', 'array:header,footer,color,display_order,agent_text,agent_color,agent_image_removed'], 'layout.header' => ['sometimes', 'nullable', 'string', 'max:1000'], 'layout.footer' => ['sometimes', 'nullable', 'string', 'max:1000'], 'layout.color' => ['sometimes', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/D'], 'layout.display_order' => ['sometimes', 'array', 'size:8'], 'layout.display_order.*' => ['string', 'distinct'], 'layout.agent_text' => ['sometimes', 'nullable', 'string', 'max:1000'], 'layout.agent_color' => ['sometimes', 'string', 'regex:/\A#[0-9a-fA-F]{6}\z/D'], 'layout.agent_image_removed' => ['sometimes', 'boolean']],
            'options','policy','receipt','show' => [],
            default => $read,
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'الطلب يحتوي حقولًا غير مسموحة.');
            }
            if ($this->input('policy.daily_product_mode') === 'all' && $this->input('policy.daily_products', []) !== []) {
                $validator->errors()->add('policy.daily_products', 'الفئات المخصصة تتطلب اختيار وضع الفئات المحددة.');
            }
        });
    }
}
