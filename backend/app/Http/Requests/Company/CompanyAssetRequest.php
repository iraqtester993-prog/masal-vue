<?php

namespace App\Http\Requests\Company;

use App\Services\Company\CompanyAccess;
use Illuminate\Foundation\Http\FormRequest;

class CompanyAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CompanyAccess::class)->require($this->user());

        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:684', 'dimensions:max_width=4096,max_height=4096']];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), ['file'])) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
            if ($this->hasFile('file') && $this->file('file')->getSize() > 700000) {
                $validator->errors()->add('file', 'حجم الصورة يتجاوز 700000 بايت.');
            }
        }];
    }
}
