<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Services\AccountScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAccountAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $account = app(AccountScope::class)->query($this->user())->findOrFail((int) $this->route('id'));
        $this->attributes->set('attachment.account', $account);

        return $this->user()->can('manageAttachments', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['agent_image', 'personal_image', 'document'])],
            'document_type' => ['required_if:kind,document', 'nullable', Rule::in(array_keys(AccountAttachment::DOCUMENT_LABELS))],
            'file' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'dimensions:max_width=4096,max_height=4096', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile && $value->getSize() > 700000) {
                    $fail('اختر صورة PNG أو JPG أو WEBP بحجم أقل من 700 كيلوبايت.');
                }
            }],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->except('_token')), ['version', 'kind', 'document_type', 'file']) as $field) {
                $validator->errors()->add($field, 'هذا الحقل غير مسموح.');
            }
            /** @var Account $account */
            $account = $this->attributes->get('attachment.account');
            if (($account->type === AccountType::Pos && $this->input('kind') === 'agent_image')
                || ($account->type !== AccountType::Pos && in_array($this->input('kind'), ['personal_image', 'document'], true))) {
                $validator->errors()->add('kind', 'نوع الصورة لا يوافق نوع الحساب.');
            }
            if ($this->input('kind') !== 'document' && $this->filled('document_type')) {
                $validator->errors()->add('document_type', 'تصنيف الوثيقة متاح للمستمسكات فقط.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'file.image' => 'اختر صورة PNG أو JPG أو WEBP صالحة.',
            'file.mimes' => 'اختر صورة PNG أو JPG أو WEBP صالحة.',
            'file.dimensions' => 'أبعاد الصورة يجب ألا تتجاوز 4096 بكسل.',
            'document_type.required_if' => 'اختر نوع المستمسك.',
        ];
    }
}
