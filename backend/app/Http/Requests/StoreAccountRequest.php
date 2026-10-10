<?php

namespace App\Http\Requests;

use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreAccountRequest extends FormRequest
{
    private ?string $identifierEmail = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Account::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge(AccountDataRequest::fieldRules(), [
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(['main_agent', 'sub_agent', 'sub_branch', 'pos'])],
            'parent_id' => ['required', 'integer'],
            'pos_type_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'prohibited_unless:type,pos'],
            'representative_ids' => ['sometimes', 'array', 'max:100', 'prohibited_unless:type,pos'],
            'representative_ids.*' => ['integer', 'distinct', 'min:1'],
            'user' => ['required', 'array:name,login,email,password,password_confirmation'],
            'user.name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'user.login' => ['required_without:user.email', 'nullable', 'string', 'regex:/^[a-z][a-z0-9._-]{2,63}$/', 'unique:users,login'],
            'user.email' => ['required_without:user.login', 'nullable', 'email', 'max:190', 'unique:users,email'],
            'user.password' => ['required', 'string', 'confirmed', Password::min(9), function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('كلمة المرور يجب ألا تتجاوز 72 بايت.');
                }
            }],
            'user.password_confirmation' => ['required', 'string'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        $user = $this->input('user');
        if (is_array($user)) {
            foreach (['login', 'email'] as $field) {
                if (isset($user[$field]) && is_string($user[$field])) {
                    $user[$field] = mb_strtolower(trim($user[$field]));
                }
            }
            if (isset($user['login']) && is_string($user['login']) && str_contains($user['login'], '@')) {
                $this->identifierEmail = $user['login'];
                if (! isset($user['email'])) {
                    $user['email'] = $user['login'];
                }
                $user['login'] = null;
            }
            $this->merge(['user' => $user]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->except('_token')), array_merge(AccountDataRequest::FIELDS, ['type', 'parent_id', 'user', 'pos_type_id', 'representative_ids'])) as $field) {
                $validator->errors()->add($field, 'هذا الحقل غير مسموح.');
            }
            if ($this->identifierEmail && $this->identifierEmail !== $this->input('user.email')) {
                $validator->errors()->add('user.login', 'معرف الدخول والبريد غير متطابقين.');
            }
        }];
    }
}
