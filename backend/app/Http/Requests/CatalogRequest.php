<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class CatalogRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['required' => ':attribute مطلوب.', 'required_if' => ':attribute مطلوب لهذا الاختيار.', 'present' => ':attribute مطلوب.', 'string' => 'أدخل نصًا صحيحًا في :attribute.', 'integer' => ':attribute يجب أن يكون عددًا صحيحًا.', 'numeric' => ':attribute يجب أن يكون رقمًا.', 'decimal' => ':attribute يقبل منزلتين عشريتين كحد أقصى.', 'in' => 'اختيار :attribute غير صالح.', 'exists' => ':attribute غير موجود أو غير صالح.', 'distinct' => ':attribute يتضمن قيمة مكررة.', 'array' => ':attribute غير صالح.', 'max' => ':attribute يتجاوز الحد المسموح.', 'min' => ':attribute أقل من الحد المسموح.', 'boolean' => ':attribute غير صالح.', 'date_format' => ':attribute يجب أن يكون تاريخًا صحيحًا.', 'after_or_equal' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.', 'file' => 'اختر ملف صورة صحيحًا.', 'mimes' => 'الصورة يجب أن تكون PNG أو JPEG.', 'dimensions' => 'أبعاد الصورة تتجاوز 4096 بكسل.'];
    }

    public function attributes(): array
    {
        return ['name' => 'الاسم', 'supplier' => 'الجهة المجهزة', 'connection' => 'نوع الربط', 'provider_id' => 'الشركة', 'face_value' => 'القيمة الاسمية', 'currency' => 'عملة القيمة الاسمية', 'minimum_price' => 'أقل سعر بيع مسموح', 'daily_limit_type' => 'نوع الحد اليومي', 'daily_quantity' => 'حد الكمية اليومي', 'daily_amount' => 'الحد المالي اليومي', 'field_policy' => 'بيانات البطاقة', 'extra_fields' => 'الحقول الإضافية', 'display_order' => 'ترتيب الظهور', 'import_codes' => 'معرّفات الفئة', 'receipt_language' => 'لغة الوصل', 'receipt_width' => 'عرض الوصل', 'receipt_header' => 'النص أعلى البطاقة', 'receipt_footer' => 'النص أسفل البطاقة', 'allowed_cities' => 'المحافظات المسموحة', 'image' => 'الصورة', 'from' => 'تاريخ البداية', 'to' => 'تاريخ النهاية', 'version' => 'نسخة السجل', 'product_ids' => 'الفئات المسموحة', 'hidden_columns' => 'الأعمدة'];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        if ($action === 'savePreferences') {
            return ['hidden_columns' => ['present', 'array', 'max:20'], 'hidden_columns.*' => ['string', 'distinct', 'in:importCodes,image,kind,provider,face,currency,min,dailyQty,dailyAmount,field_pin,field_expiry,field_serial,field_cvc,field_reference,allowedCities,receiptWidth,receiptHeader,receiptFooter,order,active']];
        }
        $version = ['version' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:2000']];
        if ($this->isMethod('GET')) {
            return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'query' => ['nullable', 'string', 'max:160'], 'provider' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', 'in:محلية,عالمية'], 'state' => ['nullable', 'in:active,inactive'], 'supplier' => ['nullable', 'string', 'max:160'], 'connection' => ['nullable', 'in:ملفات,API'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_merge(['nullable', 'date_format:Y-m-d'], $this->filled('from') ? ['after_or_equal:from'] : [])];
        }
        if (str_contains($action, 'Status')) {
            return $version + ['status' => ['required', 'in:active,disabled']];
        }
        if ($action === 'moveProduct') {
            return $version + ['direction' => ['required', 'in:up,down']];
        }
        if ($action === 'saveCategories') {
            return $version + ['catalog_version' => ['required', 'integer', 'min:1'], 'product_ids' => ['present', 'array', 'max:10000'], 'product_ids.*' => ['integer', 'distinct']];
        }
        $common = ['name' => ['required', 'string', 'max:160'], 'image' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:684', 'dimensions:max_width=4096,max_height=4096'], 'remove_image' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:2000']];
        if (str_starts_with($action, 'update')) {
            $common += $version;
        }
        if (str_contains($action, 'Provider')) {
            return $common + ['supplier' => ['required', 'string', 'max:160'], 'connection' => ['required', 'in:ملفات,API']];
        }

        return $common + [
            'provider_id' => ['required', 'integer', 'exists:catalog_providers,id'], 'kind' => ['required', 'in:محلية,عالمية'],
            'face_value' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:999999999999.99'], 'currency' => ['required', 'in:IQD'],
            'minimum_price' => ['required', 'numeric', 'decimal:0,2', 'min:1', 'max:999999999999.99'],
            'daily_limit_type' => ['required', 'in:quantity,amount'],
            'daily_quantity' => ['required_if:daily_limit_type,quantity', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'daily_amount' => ['required_if:daily_limit_type,amount', 'nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999999.99'],
            'field_policy' => ['required', 'array:pin,expiry,serial,cvc,reference'], 'field_policy.pin' => ['required', 'in:required'], 'field_policy.expiry' => ['required', 'in:required'],
            'field_policy.serial' => ['required', 'in:required,unused'], 'field_policy.cvc' => ['required', 'in:required,unused'], 'field_policy.reference' => ['required', 'in:required,unused'],
            'extra_fields' => ['present', 'array', 'max:30'], 'extra_fields.*' => ['array:key,label,required'],
            'extra_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/', 'distinct', 'not_in:pin,expiry,serial,cvc,reference,constructor,prototype,__proto__'],
            'extra_fields.*.label' => ['required', 'string', 'max:160'], 'extra_fields.*.required' => ['required', 'boolean'],
            'display_order' => ['required', 'integer', 'min:0', 'max:1000000'],
            'import_codes' => ['present', 'array', 'max:50'], 'import_codes.*' => ['string', 'max:80', 'distinct'],
            'receipt_language' => ['nullable', 'in:ar,en,ku'], 'receipt_width' => ['required', 'in:58,80'],
            'receipt_header' => ['nullable', 'string', 'max:1000'], 'receipt_footer' => ['nullable', 'string', 'max:1000'],
            'allowed_cities' => ['present', 'array', 'max:19'], 'allowed_cities.*' => ['string', 'distinct', 'exists:account_cities,name'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('payload')) {
            try {
                $payload = json_decode($this->input('payload'), true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['payload' => 'بيانات النموذج غير صالحة.']);
            }
            if (! is_array($payload) || array_is_list($payload) || array_diff(array_keys($this->input()), ['payload', '_method']) || ($this->has('_method') && $this->input('_method') !== 'PATCH')) {
                throw ValidationException::withMessages(['payload' => 'بيانات النموذج غير صالحة.']);
            }
            $this->replace($payload);
        }
        foreach (['name', 'supplier'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function after(): array
    {
        return [function ($validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules()))) {
                $validator->errors()->add('payload', 'يتضمن الطلب حقولًا غير مسموحة.');
            }
            if ($this->hasFile('image') && $this->file('image')->getSize() > 700000) {
                $validator->errors()->add('image', 'حجم الصورة يتجاوز 700000 بايت.');
            }
            if ($this->hasFile('image') && $this->boolean('remove_image')) {
                $validator->errors()->add('image', 'اختر صورة جديدة أو الإزالة فقط.');
            }
            if (($this->input('daily_limit_type') === 'quantity' && $this->filled('daily_amount')) || ($this->input('daily_limit_type') === 'amount' && $this->filled('daily_quantity'))) {
                $validator->errors()->add('daily_limit_type', 'سيُطبّق حد يومي واحد فقط.');
            }
        }];
    }
}
