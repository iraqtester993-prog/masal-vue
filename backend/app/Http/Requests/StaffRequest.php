<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['login', 'email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => mb_strtolower(trim($this->input($field)))]);
            }
        }
    }

    public function rules(): array
    {
        if ($this->route()->getActionMethod() === 'status') {
            return ['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])], 'reason' => ['required', 'string', 'max:500']];
        }
        $create = $this->isMethod('POST');
        $presence = $create ? 'required' : 'sometimes';
        $rules = [
            'name' => [$presence, 'required', 'string', 'max:190'],
            'email' => [$presence, 'required', 'email', 'max:190'],
            'permission_profile_id' => [$presence, 'integer'],
            'scope_roots' => [$presence, 'array', 'min:1', 'max:100'],
            'scope_roots.*' => ['integer', 'distinct'],
            'include_descendants' => [$presence, 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
        if ($create) {
            $rules += [
                'login' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9._-]{2,63}$/'],
                'password' => ['required', 'string', 'confirmed', Password::min(9), function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('كلمة المرور يجب ألا تتجاوز 72 بايت.');
                    }
                }],
                'password_confirmation' => ['required', 'string'],
            ];
        } else {
            $rules += ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->except('_token')), $keys) as $field) {
                $validator->errors()->add($field, 'هذا الحقل غير مسموح.');
            }
        }];
    }
}
