<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PermissionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $version = ['version' => ['required', 'integer', 'min:1']];
        $reason = ['reason' => ['sometimes', 'nullable', 'string', 'max:500']];
        if ($this->isMethod('DELETE')) {
            return $version + $reason;
        }
        if ($this->route()->getActionMethod() === 'status') {
            return $version + $reason + ['status' => ['required', Rule::in(['active', 'disabled'])]];
        }
        $create = $this->isMethod('POST');

        return ['name' => [$create ? 'required' : 'sometimes', 'required', 'string', 'max:80'],
            'permissions' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:300'],
            'permissions.*' => ['string', 'distinct']]
            + ($create ? [] : $version + ['reason' => ['required', 'string', 'max:500']]);
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
