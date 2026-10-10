<?php

namespace App\Http\Requests\Maps;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $coordinates = ['latitude' => ['required', 'numeric', 'between:-85,85'], 'longitude' => ['required', 'numeric', 'between:-180,180']];

        return match ($this->route()->getActionMethod()) {
            'heartbeat' => ['device_model' => ['sometimes', 'string', 'max:120'], 'app_version' => ['sometimes', 'string', 'max:60']],
            'locate' => $coordinates + ['consent_version' => ['required', 'integer', 'min:1'], 'accuracy' => ['required', 'numeric', 'between:0,100000'], 'recorded_at' => ['required', 'date', 'after_or_equal:'.now()->subMinutes(5)->toIso8601String(), 'before_or_equal:'.now()->addMinute()->toIso8601String()]],
            'saveLocation' => $coordinates + ['version' => ['required', 'integer', 'min:0']],
            'disconnect','own' => [],
            default => ['query' => ['sometimes', 'nullable', 'string', 'max:200'], 'type' => ['sometimes', 'nullable', Rule::in(['main_agent', 'sub_agent', 'sub_branch', 'pos', 'employee'])], 'branch_id' => ['sometimes', 'integer', 'min:1'], 'status' => ['sometimes', 'nullable', Rule::in(['online', 'offline', 'without_location'])], 'user_ids' => ['sometimes', 'array', 'max:1000'], 'user_ids.*' => ['integer', 'min:1', 'distinct'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100']],
        };
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
