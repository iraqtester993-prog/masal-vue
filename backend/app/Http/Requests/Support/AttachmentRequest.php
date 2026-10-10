<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['support', 'notification'])], 'file' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:684', 'dimensions:max_width=4096,max_height=4096']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
            if ($this->hasFile('file') && $this->file('file')->getSize() > 700000) {
                $validator->errors()->add('file', 'حجم الصورة يتجاوز 700000 بايت.');
            }
        }];
    }
}
