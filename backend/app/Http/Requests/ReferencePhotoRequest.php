<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReferencePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']];
        if ($this->isMethod('POST')) {
            $rules['file'] = ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:684', 'dimensions:max_width=4096,max_height=4096'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'max' => ':attribute يتجاوز الحد المسموح.', 'file' => 'اختر ملف صورة صحيحًا.', 'mimes' => 'الصورة يجب أن تكون PNG أو JPEG أو WEBP.', 'dimensions' => 'أبعاد الصورة تتجاوز 4096 بكسل.'];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
            if ($this->hasFile('file') && $this->file('file')->getSize() > 700000) {
                $validator->errors()->add('file', 'حجم الصورة يتجاوز 700000 بايت.');
            }
        }];
    }
}
