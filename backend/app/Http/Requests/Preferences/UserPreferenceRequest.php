<?php

namespace App\Http\Requests\Preferences;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return $this->isMethod('PUT') ? ['language' => ['required', Rule::in(['ar', 'en', 'ckb'])], 'theme' => ['required', Rule::in(['light', 'dark'])], 'version' => ['required', 'integer', 'min:0', 'max:4294967294']] : [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token')), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
        });
    }
}
