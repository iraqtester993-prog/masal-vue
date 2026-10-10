<?php

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'];
        $version = ['required', 'integer', 'min:1'];
        $content = ['title' => ['required', 'string', 'max:200'], 'description' => ['required', 'string', 'max:10000'], 'attachment_id' => ['nullable', 'integer', 'min:1'], 'idempotency_key' => $key];

        return match ($this->route('support_action')) {
            'index','notices','export' => ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'query' => ['nullable', 'string', 'max:200'], 'filter' => ['sometimes', Rule::in(['all', 'unread'])], 'locale' => ['sometimes', Rule::in(['ar', 'en', 'ckb'])]],
            'create' => $content + ['recipient_id' => ['required', 'integer', 'min:1']],
            'broadcast' => $content + ['mode' => ['required', Rule::in(['all', 'agents', 'branches', 'custom'])], 'user_ids' => ['sometimes', 'array', 'list', 'max:10000'], 'user_ids.*' => ['integer', 'min:1', 'distinct:strict']],
            'reply' => ['body' => ['required', 'string', 'max:10000'], 'version' => $version, 'idempotency_key' => $key],
            'status' => ['action' => ['required', Rule::in(['close', 'escalate'])], 'version' => $version, 'idempotency_key' => $key],
            'phones' => ['phones' => ['required', 'array', 'list', 'max:5'], 'phones.*' => ['array:label,number'], 'phones.*.label' => ['required', 'string', 'max:60'], 'phones.*.number' => ['required', 'string', 'regex:/^07[78][0-9]{8}$/'], 'version' => ['required', 'integer', 'min:0'], 'idempotency_key' => $key],
            'notice-create' => ['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000'], 'attachment_id' => ['nullable', 'integer', 'min:1'], 'idempotency_key' => $key, 'mode' => ['required', Rule::in(['all', 'agents', 'branches', 'points', 'custom'])], 'user_ids' => ['sometimes', 'array', 'list', 'max:10000'], 'user_ids.*' => ['integer', 'min:1', 'distinct:strict'], 'translations' => ['sometimes', 'array:en,ckb'], 'translations.*' => ['array:title,body'], 'translations.*.title' => ['nullable', 'string', 'max:200'], 'translations.*.body' => ['nullable', 'string', 'max:10000']],
            'read-page' => ['page' => ['required', Rule::in(['support', 'import', 'inventory', 'claims', 'exports', 'wallets', 'invoices', 'prices', 'sales', 'exceptions', 'accounts', 'security', 'branding'])]],
            default => [],
        };
    }

    public function after(): array
    {
        return [function ($validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($name) => ! str_contains($name, '.'));
            if (array_diff(array_keys($this->all()), $allowed)) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
            foreach (['title', 'description', 'body'] as $field) {
                if ($this->has($field) && trim((string) $this->input($field)) === '') {
                    $validator->errors()->add($field, 'الحقل مطلوب.');
                }
            }
            if ($this->input('mode') === 'custom' && ! $this->input('user_ids')) {
                $validator->errors()->add('user_ids', 'اختر المستلمين.');
            }
            if ($this->has('user_ids') && $this->input('mode') !== 'custom') {
                $validator->errors()->add('user_ids', 'المستلمون المحددون مسموحون فقط للنمط المخصص.');
            }
            foreach ((array) $this->input('translations', []) as $locale => $translation) {
                if (! is_array($translation)) {
                    continue;
                }
                $title = trim((string) ($translation['title'] ?? ''));
                $body = trim((string) ($translation['body'] ?? ''));
                if (($title === '') !== ($body === '')) {
                    $validator->errors()->add('translations.'.$locale, 'أدخل عنوان الترجمة ونصها معًا.');
                }
            }
        }];
    }
}
