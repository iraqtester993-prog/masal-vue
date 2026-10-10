<?php

namespace App\Http\Requests\Company;

use App\Services\Company\CompanyAccess;
use Illuminate\Foundation\Http\FormRequest;

class CompanyConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->is('api/v1/support/*')) {
            app(CompanyAccess::class)->requireInbox($this->user(), true);
        }

        return true;
    }

    public function rules(): array
    {
        $public = ! $this->is('api/v1/support/*');
        $rules = $public ? ['token' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}\.[a-f0-9]{64}$/D']] : ['version' => ['required', 'integer', 'min:1']];
        if (! $this->is('api/v1/company/inquiries/track')) {
            $rules += ['body' => ['required', 'string', 'max:3000'], 'idempotency_key' => ['required', 'uuid']];
            if ($public) {
                $rules['website_honeypot'] = ['present', 'nullable', 'string', 'max:0'];
            }
        }

        return $rules;
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'حقول غير مسموحة.');
            }
        }];
    }
}
