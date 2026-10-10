<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountsQueryRequest extends FormRequest
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
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:190'],
            'kind' => ['sometimes', Rule::in(['agents', 'pos'])],
            'type' => ['sometimes', Rule::in(['system', 'main_agent', 'sub_agent', 'sub_branch', 'pos'])],
            'status' => ['sometimes', Rule::in(['active', 'disabled'])],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'parent_id' => ['sometimes', 'integer', 'min:1'],
            'ancestor_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
