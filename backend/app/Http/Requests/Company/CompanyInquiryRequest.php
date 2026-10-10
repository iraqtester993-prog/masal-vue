<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;

class CompanyInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'contact' => ['required', 'string', 'max:150'], 'message' => ['required', 'string', 'max:3000'], 'idempotency_key' => ['required', 'uuid'], 'website_honeypot' => ['present', 'nullable', 'string', 'max:0']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
        }];
    }
}
