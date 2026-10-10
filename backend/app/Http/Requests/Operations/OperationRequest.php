<?php

namespace App\Http\Requests\Operations;

use App\Services\Operations\AccountTimeGuard;
use App\Services\Operations\OperationGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:-]+$/'];
        $version = ['required', 'integer', 'min:0'];
        $policy = ['policy' => ['required', 'array:'.implode(',', array_keys(AccountTimeGuard::emptyPolicy()))]];
        foreach (['enabled', 'dateEnabled', 'hoursEnabled', 'idleEnabled', 'sessionEnabled'] as $name) {
            $policy['policy.'.$name] = ['required', 'boolean'];
        }
        foreach (['startAt', 'endAt'] as $name) {
            $policy['policy.'.$name] = ['nullable', 'string', 'date_format:Y-m-d\TH:i'];
        }
        foreach (['startTime', 'endTime'] as $name) {
            $policy['policy.'.$name] = ['nullable', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'];
        }
        foreach (['idleMinutes', 'sessionMinutes'] as $name) {
            $policy['policy.'.$name] = ['required', 'integer', 'min:1', 'max:525600'];
        }

        return match ($this->route('operation_action')) {
            'security','archive-index' => ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'query' => ['nullable', 'string', 'max:200']],
            'stop-create' => ['scope' => ['required', Rule::in(['all', 'main', 'branch', 'subbranch', 'pos', 'custom'])], 'actions' => ['required', 'array', 'list', 'min:1', 'max:5'], 'actions.*' => ['required', Rule::in(OperationGuard::KEYS), 'distinct:strict'], 'account_ids' => ['sometimes', 'array', 'list', 'max:10000'], 'reason' => ['required', 'string', 'max:300'], 'idempotency_key' => $key],
            'resume','restore-global' => ['version' => $version, 'idempotency_key' => $key],
            'direct' => ['stops' => ['required', 'array:login,sales,printing,import'], 'stops.*' => ['boolean'], 'version' => $version, 'idempotency_key' => $key],
            'time-save' => $policy + ['version' => $version, 'idempotency_key' => $key],
            'archive-create' => ['reason' => ['required', 'string', 'max:500'], 'password' => ['required', 'string', 'max:1024'], 'version' => ['required', 'integer', 'min:1'], 'idempotency_key' => $key],
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
            if ($this->has('reason') && trim((string) $this->input('reason')) === '') {
                $validator->errors()->add('reason', 'اكتب السبب.');
            }
            if ($this->has('account_ids')) {
                $ids = $this->input('account_ids');
                $seen = [];
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        if (! is_int($id) || $id < 1 || isset($seen[$id])) {
                            $validator->errors()->add('account_ids', 'اختر حسابات صحيحة دون تكرار.');
                            break;
                        } $seen[$id] = true;
                    }
                }
                if ($this->input('scope') !== 'custom') {
                    $validator->errors()->add('account_ids', 'تحديد الحسابات مسموح للنطاق المخصص فقط.');
                }
            }
            if ($this->input('scope') === 'custom' && ! $this->input('account_ids')) {
                $validator->errors()->add('account_ids', 'اختر الحسابات.');
            }
            $p = $this->input('policy');
            if (is_array($p) && ($p['enabled'] ?? false)) {
                if (! array_filter(array_intersect_key($p, array_flip(['dateEnabled', 'hoursEnabled', 'idleEnabled', 'sessionEnabled'])))) {
                    $validator->errors()->add('policy', 'فعّل قيدًا واحدًا على الأقل.');
                }
                if (($p['dateEnabled'] ?? false) && (empty($p['startAt']) || empty($p['endAt']) || $p['endAt'] <= $p['startAt'])) {
                    $validator->errors()->add('policy.endAt', 'حدد بداية ونهاية صالحتين؛ النهاية بعد البداية.');
                }
                if (($p['hoursEnabled'] ?? false) && (empty($p['startTime']) || empty($p['endTime']) || $p['startTime'] === $p['endTime'])) {
                    $validator->errors()->add('policy.endTime', 'حدد ساعات دوام مختلفة للبداية والنهاية.');
                }
            }
        }];
    }
}
