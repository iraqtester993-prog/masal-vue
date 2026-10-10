<?php

namespace App\Http\Requests\Stock;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class StockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $operation = ['idempotency_key' => ['required', 'string', 'regex:/^[a-zA-Z0-9._:-]{8,100}$/']];
        $version = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:1', 'max:2000']];
        if ($this->isMethod('GET')) {
            return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'query' => ['nullable', 'string', 'max:160'], 'account_id' => ['nullable', 'integer', 'min:1'], 'batch_id' => ['nullable', 'integer', 'min:1'], 'provider_id' => ['nullable', 'integer', 'min:1'], 'product_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'string', 'max:32'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'mode' => ['nullable', 'in:all,remaining']];
        }
        if (in_array($action, ['preview', 'submit', 'resubmit'], true)) {
            $rules = ['order_key' => ['required', 'string', 'regex:/^[a-zA-Z0-9._:-]{8,100}$/'], 'account_id' => ['required', 'integer', 'min:1'], 'provider_id' => ['required', 'integer', 'min:1'], 'source_id' => ['required', 'integer', 'min:1'], 'city' => ['required', 'string', 'max:80'], 'category_count' => ['required', 'integer', 'min:1', 'max:200'], 'lines' => ['required', 'array', 'list', 'min:1', 'max:200']];
            if ($action !== 'preview') {
                $rules += $operation + ['preview_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'], 'exclude_rejected' => ['required', 'boolean']];
            }
            if ($action === 'resubmit') {
                $rules['version'] = ['required', 'integer', 'min:1'];
            }

            return $rules;
        }
        if ($action === 'review') {
            return $operation + ['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'in:approve,reject,return'], 'reason' => ['required_unless:decision,approve', 'nullable', 'string', 'max:2000']];
        }
        if ($action === 'batchAction') {
            return $operation + $version + ['action' => ['required', 'in:quarantine,resume,cancel,restore,edit'], 'adjustment_id' => ['nullable', 'integer', 'min:1'], 'city' => ['nullable', 'string', 'max:80'], 'supplier' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000']];
        }
        if ($action === 'previewAction') {
            return $version + ['action' => ['required', 'in:edit,quarantine,resume,cancel,restore,damage,return,copy'], 'card_ids' => ['sometimes', 'array', 'min:1', 'max:50000'], 'adjustment_id' => ['nullable', 'integer', 'min:1'], 'secrets' => ['sometimes', 'boolean'], 'city' => ['nullable', 'string', 'max:80'], 'supplier' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:2000']];
        }
        if ($action === 'claim') {
            return $operation + $version + ['card_ids' => ['required', 'array', 'min:1', 'max:50000']];
        }
        if ($action === 'settleClaim') {
            return $operation + $version + ['decision' => ['required', 'in:restore,compensate,reject,loss,replace'], 'replacement_rows' => ['nullable', 'array', 'min:1', 'max:50000']];
        }
        if ($action === 'requestWithdrawal') {
            return $operation + $version + ['batch_id' => ['required', 'integer', 'min:1'], 'card_ids' => ['sometimes', 'array', 'min:1', 'max:50000']];
        }
        if ($action === 'reviewWithdrawal') {
            return $operation + $version + ['decision' => ['required', 'in:approve,reject'], 'hours' => ['required_if:decision,approve', 'nullable', 'integer', 'min:1', 'max:72']];
        }
        if ($action === 'downloadWithdrawal') {
            return $operation + $version;
        }
        if ($action === 'copy') {
            return ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000'], 'card_ids' => ['required', 'array', 'min:1', 'max:50000'], 'secrets' => ['required', 'boolean']];
        }

        return [];
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'required_if' => ':attribute مطلوب لهذا الاختيار.', 'required_unless' => ':attribute مطلوب لهذا الاختيار.', 'string' => ':attribute يجب أن يكون نصًا.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'max' => ':attribute يتجاوز الحد المسموح.', 'min' => ':attribute أقل من الحد المسموح.', 'in' => 'اختيار :attribute غير صالح.', 'array' => ':attribute غير صالح.', 'distinct' => ':attribute يتضمن قيمة مكررة.', 'regex' => ':attribute بصيغة غير صالحة.'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'city', 'supplier', 'notes'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim($this->input($key))]);
            }
        }
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
            if (is_array($this->input('lines')) && array_sum(array_map(fn ($line): int => is_array($line) && is_array($line['rows'] ?? null) ? count($line['rows']) : 0, $this->input('lines'))) > 50000) {
                $validator->errors()->add('lines', 'الحد الأقصى للطلبية 50000 بطاقة.');
            }
            if (in_array($this->route()->getActionMethod(), ['preview', 'submit', 'resubmit'], true) && is_array($this->input('lines'))) {
                $seen = [];
                $lineRules = ['key' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:200'], 'category_code' => ['nullable', 'string', 'max:80'], 'product_id' => ['nullable', 'integer', 'min:1'], 'declared_count' => ['nullable', 'integer', 'min:1', 'max:50000'], 'cost' => ['required', 'string', 'max:16'], 'expenses' => ['required', 'string', 'max:16'], 'default_expiry' => ['nullable', 'string', 'max:30'], 'rows' => ['required', 'array', 'list', 'min:1', 'max:50000']];
                foreach ($this->input('lines') as $index => $line) {
                    if (! is_array($line) || array_is_list($line) || array_diff(array_keys($line), array_keys($lineRules))) {
                        $validator->errors()->add('lines.'.$index, 'يتضمن الملف حقولًا غير مسموحة.');

                        continue;
                    }
                    $check = Validator::make($line, $lineRules);
                    foreach ($check->errors()->messages() as $field => $errors) {
                        foreach ($errors as $error) {
                            $validator->errors()->add('lines.'.$index.'.'.$field, $error);
                        }
                    }
                    if (is_string($line['key'] ?? null)) {
                        if (isset($seen[$line['key']])) {
                            $validator->errors()->add('lines.'.$index.'.key', 'مفتاح الملف مكرر.');
                        }
                        $seen[$line['key']] = true;
                    }
                }
            }
            if (is_string($this->input('from')) && is_string($this->input('to')) && $this->input('from') > $this->input('to')) {
                $validator->errors()->add('to', 'نهاية الفترة يجب أن تكون بعد بدايتها.');
            }
            if (is_array($this->input('card_ids'))) {
                $ids = $this->input('card_ids');
                $seen = [];
                if (! array_is_list($ids)) {
                    $validator->errors()->add('card_ids', 'تحديد البطاقات يجب أن يكون قائمة معرفات.');
                }
                foreach ($ids as $id) {
                    if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                        $validator->errors()->add('card_ids', 'معرف بطاقة غير صحيح أو مكرر.');
                        break;
                    }
                    $seen[$id] = true;
                }
            }
        }];
    }
}
