<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $version = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']];
        if ($this->isMethod('GET')) {
            return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'query' => ['nullable', 'string', 'max:160'], 'status' => ['nullable', 'in:active,disabled'], 'provider_id' => ['nullable', 'integer', 'min:1'], 'network_account_id' => ['nullable', 'integer', 'min:1'], 'agent_account_id' => ['nullable', 'integer', 'min:1'], 'city' => ['nullable', 'string', 'max:80']];
        }
        if ($action === 'status') {
            return $version + ['status' => ['required', 'in:active,disabled']];
        }
        if ($action === 'saveProfile') {
            return $version + ['pos_type_id' => ['present', 'nullable', 'integer', 'min:1'], 'representative_ids' => ['present', 'array', 'max:100'], 'representative_ids.*' => ['integer', 'distinct', 'min:1']];
        }
        $rules = ['name' => ['required', 'string', 'max:160'], 'reason' => ['nullable', 'string', 'max:2000']];
        if ($action === 'update') {
            $rules += $version;
        }
        if ($this->route('kind') === 'sources') {
            $rules['name'] = ['required', 'string', 'max:150'];
            $rules += ['provider_id' => ['required', 'integer', 'min:1'], 'network_account_id' => ['nullable', 'integer', 'min:1']];
        } elseif ($this->route('kind') === 'representatives') {
            $rules['name'] = ['nullable', 'string', 'max:160'];
            $rules += ['phone' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:500'], 'agent_account_id' => ['required', 'integer', 'min:1']];
        } elseif ($this->route('kind') === 'pos-types') {
            $rules['status'] = ['sometimes', 'in:active,disabled'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'address'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'present' => ':attribute مطلوب.', 'string' => ':attribute يجب أن يكون نصًا.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'max' => ':attribute يتجاوز الحد المسموح.', 'min' => ':attribute أقل من الحد المسموح.', 'in' => 'اختيار :attribute غير صالح.', 'array' => ':attribute غير صالح.', 'distinct' => ':attribute يتضمن قيمة مكررة.'];
    }

    public function attributes(): array
    {
        return ['name' => 'الاسم', 'provider_id' => 'الشركة', 'network_account_id' => 'الوكيل الرئيسي', 'agent_account_id' => 'الوكيل', 'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'pos_type_id' => 'نوع نقطة البيع', 'representative_ids' => 'المندوبون', 'version' => 'نسخة السجل', 'status' => 'الحالة'];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
        }];
    }
}
