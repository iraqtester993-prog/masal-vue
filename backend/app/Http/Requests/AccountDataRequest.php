<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AccountDataRequest extends FormRequest
{
    public const FIELDS = ['name', 'city', 'phone', 'support', 'color', 'owner_name', 'address', 'serial', 'device_lock_enabled', 'device_model', 'app_version', 'notes'];

    public function authorize(): bool
    {
        return true;
    }

    public static function fieldRules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:190'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[+0-9٠-٩۰-۹() -]+$/u'],
            'support' => ['sometimes', 'nullable', 'string', 'max:500'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'serial' => ['sometimes', 'nullable', 'string', 'max:190'],
            'device_lock_enabled' => ['sometimes', 'boolean'],
            'device_model' => ['sometimes', 'nullable', 'string', 'max:190'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:190'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'status' => ['version' => ['required', 'integer', 'min:1'], 'status' => ['required', Rule::in(['active', 'disabled'])], 'reason' => ['required', 'string', 'max:500']],
            'permissions' => ['version' => ['required', 'integer', 'min:1'], 'permissions' => ['present', 'array', 'max:300'], 'permissions.*' => ['string', 'distinct'], 'reason' => ['required', 'string', 'max:500']],
            'login' => ['version' => ['required', 'integer', 'min:1'], 'login' => ['required', 'string', 'max:190'], 'reason' => ['required', 'string', 'max:500']],
            default => self::fieldRules() + ['version' => ['required', 'integer', 'min:1'], 'login' => ['sometimes', 'required', 'string', 'max:190'], 'reason' => [$this->has('login') ? 'required' : 'sometimes', 'nullable', 'string', 'max:500'], 'pos_type_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'representative_ids' => ['sometimes', 'array', 'max:100'], 'representative_ids.*' => ['integer', 'distinct', 'min:1']],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->except('_token')), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'هذا الحقل غير مسموح.');
            }
        }];
    }
}
