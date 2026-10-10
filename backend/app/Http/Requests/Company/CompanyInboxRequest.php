<?php

namespace App\Http\Requests\Company;

use App\Services\Company\CompanyAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyInboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->is('api/v1/support/site-inquiries*')) {
            app(CompanyAccess::class)->requireInbox($this->user(), $this->isMethod('PATCH'));
        } else {
            app(CompanyAccess::class)->require($this->user());
        }

        return true;
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? ['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['new', 'followed'])]] : ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', Rule::in([20, 50, 100])], 'status' => ['sometimes', Rule::in(['new', 'followed'])], 'q' => ['sometimes', 'nullable', 'string', 'max:200']];
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
