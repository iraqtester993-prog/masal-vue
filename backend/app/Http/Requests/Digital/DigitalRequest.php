<?php

namespace App\Http\Requests\Digital;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

class DigitalRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($this->isMethod('GET')) {
            return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'q' => ['nullable', 'string', 'max:160'], 'provider' => ['nullable', 'in:rabiaa,topup'], 'account_id' => ['nullable', 'integer', 'min:1'], 'main_account_id' => ['nullable', 'integer', 'min:1'], 'product_id' => ['nullable', 'integer', 'min:1'], 'status' => ['nullable', 'in:pending,review,succeeded,failed,refunded'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
        }
        $key = ['idempotency_key' => ['required', 'string', 'regex:/\A[a-zA-Z0-9._:-]{8,100}\z/']];
        $version = ['version' => ['required', 'integer', 'min:0']];
        if (in_array($action, ['storeConnection', 'updateConnection'], true)) {
            return $key + $version + ['provider' => ['required', 'in:rabiaa,topup'], 'account_id' => ['required', 'integer', 'min:1'], 'provider_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'active' => ['required', 'boolean'], 'credential' => ['sometimes', 'string', 'min:8', 'max:8000'], 'catalog_snapshot_id' => ['sometimes', 'integer', 'min:1'], 'offers' => ['present', 'array', 'list', 'max:500']];
        }
        if ($action === 'synchronize') {
            return $version + ['catalog_only' => ['sometimes', 'boolean']];
        }
        if ($action === 'catalog') {
            return $version;
        }
        if ($action === 'balance') {
            return [];
        }
        if ($action === 'grant') {
            return $key + $version + ['offer_ids' => ['present', 'array', 'list', 'max:500'], 'offer_ids.*' => ['integer', 'min:1', 'distinct:strict'], 'active' => ['required', 'boolean']];
        }
        if ($action === 'store') {
            return ['request_id' => ['required', 'string', 'regex:/\A[a-zA-Z0-9._:-]{8,100}\z/'], 'connection_id' => ['required', 'integer', 'min:1'], 'offer_id' => ['required', 'integer', 'min:1'], 'offer_version' => ['required', 'integer', 'min:1'], 'expected_retail' => ['required', 'string', 'max:16'], 'mobile' => ['sometimes', 'nullable', 'string', 'max:40'], 'confirm_mobile' => ['sometimes', 'nullable', 'string', 'max:40'], 'first_name' => ['sometimes', 'string', 'max:100'], 'last_name' => ['sometimes', 'string', 'max:100'], 'bein_province_id' => ['sometimes', 'string', 'max:30']];
        }
        if (in_array($action, ['verify', 'refundStatus'], true)) {
            return $key + ['version' => ['required', 'integer', 'min:1']];
        }
        if ($action === 'heartbeat') {
            return ['app_version' => ['required', 'string', 'regex:/\A[0-9]+\.[0-9]+\.[0-9]+\z/', 'max:30'], 'serial' => ['sometimes', 'string', 'max:190'], 'os_version' => ['sometimes', 'string', 'regex:/\A[0-9]+(?:\.[0-9]+){0,3}\z/', 'max:30']];
        }

        return [];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $field): bool => ! str_contains($field, '.'));
            if (array_diff(array_keys($this->all()), $allowed)) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
            if ($this->filled('from') && $this->filled('to') && $this->input('from') > $this->input('to')) {
                $validator->errors()->add('to', 'نهاية الفترة يجب أن تكون بعد بدايتها.');
            }
            if (is_array($this->input('offers'))) {
                $rules = ['id' => ['sometimes', 'integer', 'min:1'], 'product_id' => ['required', 'integer', 'min:1'], 'remote_id' => ['nullable', 'string', 'max:100'], 'province_id' => ['nullable', 'string', 'regex:/\A[0-9]*\z/', 'max:30'], 'bein_province_id' => ['nullable', 'string', 'regex:/\A[0-9]*\z/', 'max:30'], 'retail' => ['required', 'string', 'max:16'], 'active' => ['required', 'boolean']];
                foreach ($this->input('offers') as $index => $offer) {
                    if (! is_array($offer) || array_is_list($offer) || array_diff(array_keys($offer), array_keys($rules))) {
                        $validator->errors()->add('offers.'.$index, 'بيانات الفئة تتضمن حقولًا غير مسموحة؛ تكلفة الشركة تحفظ من القائمة الفعلية فقط.');

                        continue;
                    }
                    foreach (Validator::make($offer, $rules)->errors()->messages() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add('offers.'.$index.'.'.$field, $message);
                        }
                    }
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'string' => ':attribute يجب أن يكون نصًا.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'max' => ':attribute يتجاوز الحد المسموح.', 'in' => 'اختيار :attribute غير صالح.', 'regex' => ':attribute بصيغة غير صالحة.', 'boolean' => ':attribute يجب أن يكون اختيارًا صحيحًا.', 'distinct' => ':attribute يتضمن تكرارًا.'];
    }
}
