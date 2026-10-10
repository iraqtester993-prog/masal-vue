<?php

namespace App\Http\Requests\Reports;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
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
        if ($this->routeIs('dashboard.activity')) {
            return ['limit' => ['sometimes', 'in:10,20,50,100,all']];
        }

        return ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])], 'agent_id' => ['sometimes', 'integer', 'min:1'], 'pos_id' => ['sometimes', 'integer', 'min:1'], 'product_id' => ['sometimes', 'integer', 'min:1'], 'provider_id' => ['sometimes', 'integer', 'min:1'], 'city' => ['sometimes', 'string', 'max:190'], 'status' => ['sometimes', 'string', 'max:32'], 'kind' => ['sometimes', 'in:all,sales,inventory,wallets,network,prices,claims,support,users,audit,operations'], 'currency' => ['sometimes', 'in:IQD'], 'q' => ['sometimes', 'string', 'max:200'], 'sort' => ['sometimes', 'string', 'max:50'], 'direction' => ['sometimes', 'in:asc,desc'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'in:20,50,100'], 'detail_filter' => ['sometimes', 'in:main,branch,nested,active,inactive,available,issued,held'], 'section_ids' => ['sometimes', 'array', 'min:1', 'max:52'], 'section_ids.*' => ['string', 'distinct', 'max:80'], 'visible_columns' => ['sometimes', 'array', 'max:40'], 'visible_columns.*' => ['string', 'distinct', 'max:50'], 'purpose' => ['sometimes', 'in:export,print']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('query', 'إحدى حقول البحث غير مسموحة.');
            }
        });
    }
}
