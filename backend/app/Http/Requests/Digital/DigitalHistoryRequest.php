<?php

namespace App\Http\Requests\Digital;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DigitalHistoryRequest extends FormRequest
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
        return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100'], 'kind' => ['nullable', 'in:all,card,topup'], 'q' => ['nullable', 'string', 'max:160'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'بيانات الفلتر غير مسموحة.');
            }
            if ($this->filled('from') && $this->filled('to') && $this->input('from') > $this->input('to')) {
                $validator->errors()->add('to', 'نهاية الفترة يجب أن تكون بعد بدايتها.');
            }
        }];
    }
}
