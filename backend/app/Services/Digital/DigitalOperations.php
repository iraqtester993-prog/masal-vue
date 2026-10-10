<?php

namespace App\Services\Digital;

use App\Enums\AccountType;
use App\Http\Resources\Digital\DigitalResource;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\Sales\DeviceSession;
use App\Models\Sales\PrintPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\Ledger;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use App\Services\Operations\OperationGuard;
use App\Services\Sales\SalesAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DigitalOperations
{
    public function __construct(private DigitalAccess $access, private ProviderGateway $gateway, private AuditLogger $audit, private SalesAccess $devices) {}

    public function dto(DigitalOrder $order, Request $request): array
    {
        $order->loadMissing(['account', 'mainAccount', 'creator', 'product']);

        return (new DigitalResource($order))->resolve($request);
    }

    public function heartbeat(User $actor, array $data, Request $request): array
    {
        $own = $this->access->own($actor, 'digital.create');
        abort_unless($own->type === AccountType::Pos, 403);
        $device = DeviceSession::updateOrCreate(['session_hash' => hash('sha256', $request->session()->getId())], ['account_id' => $own->id, 'user_id' => $actor->id, 'session_version' => $actor->session_version, 'serial' => $data['serial'] ?? null, 'app_version' => $data['app_version'], 'os_version' => $data['os_version'] ?? null, 'last_seen_at' => now(), 'expires_at' => now()->addSeconds(90)]);

        return ['online' => true, 'device_lock_enabled' => $own->device_lock_enabled, 'expires_at' => $device->expires_at->toISOString(), 'app_version' => $device->app_version, 'os_version' => $device->os_version];
    }

    public function begin(User $actor, array $data, Request $request): DigitalOrder
    {
        $own = $this->access->own($actor, 'digital.create');
        abort_unless($own->type === AccountType::Pos, 403, 'بيع الخدمات الإلكترونية من صاحب نقطة البيع فقط.');
        $mobile = isset($data['mobile']) && $data['mobile'] !== '' ? DigitalMoney::phone($data['mobile']) : null;
        $subscriber = ['firstName' => trim($data['first_name'] ?? ''), 'lastName' => trim($data['last_name'] ?? ''), 'beinProvinceId' => (string) ($data['bein_province_id'] ?? '')];
        $payload = ['request_id' => $data['request_id'], 'connection_id' => $data['connection_id'], 'offer_id' => $data['offer_id'], 'offer_version' => $data['offer_version'], 'expected_retail' => DigitalMoney::minor($data['expected_retail'], 'expected_retail'), 'mobile' => $mobile, 'subscriber' => $subscriber, 'actor_id' => $actor->id, 'account_id' => $own->id];
        $hash = Ledger::hash($payload);

        return DB::transaction(function () use ($actor, $own, $data, $request, $mobile, $subscriber, $hash): DigitalOrder {
            app(MutationGuard::class)->lock($actor, [$own->id], 'digital.create');
            $own = $this->access->own($actor, 'digital.create', true);
            $old = DigitalOrder::where('request_id', $data['request_id'])->lockForUpdate()->first();
            if ($old) {
                abort_unless($old->creator_id === $actor->id && $old->account_id === $own->id && hash_equals($old->request_hash, $hash), 409, 'مرجع العملية مستخدم لطلب مختلف.');

                return $old;
            }
            abort_if(DigitalOrder::where('account_id', $own->id)->whereNull('acknowledged_at')->exists(), 409, 'راجع نتيجة العملية السابقة وأكد بدء بيع جديد قبل إرسال طلب آخر.');
            $settings = PrintPolicy::findOrFail(1)->settings;
            abort_unless($settings['sales_enabled'], 409, 'البيع موقوف من إدارة النظام.');
            $this->devices->device($actor, $own, $request, $settings);
            $connection = $this->access->connection($actor, $data['connection_id'], 'digital.create', true);
            $this->gateway->requireReady($connection, true);
            $allowedProducts = $this->access->catalog->products($actor)->pluck('catalog_products.id')->all();
            $offer = $this->access->offers($connection, $own)->firstWhere('id', $data['offer_id']);
            abort_unless($offer && ($offer->topup_category_id !== null || in_array($offer->product_id, $allowedProducts, true)) && $offer->product->currency === 'IQD', 403, 'الخدمة أو الفئة غير مفعلة لهذه النقطة.');
            $retail = app(TopupAccounting::class)->retail($offer, $own);
            abort_unless($offer->version === $data['offer_version'] && $retail === DigitalMoney::minor($data['expected_retail']), 409, 'تغير سعر الفئة؛ راجع العملية من جديد.');
            abort_if(DigitalOrder::where('account_id', $own->id)->where('reservation_active', true)->exists(), 409, 'لديك عملية بانتظار التحقق؛ تحقق منها قبل إصدار طلب آخر.');
            $needsPhone = $connection->provider === 'topup' || $offer->package_type === 'premium';
            if ($needsPhone) {
                abort_unless($mobile && isset($data['confirm_mobile']) && DigitalMoney::phone($data['confirm_mobile']) === $mobile, 422, 'رقم الزبون وتأكيده غير متطابقين.');
            } else {
                abort_if($mobile !== null, 422, 'هذه البطاقة لا تتطلب رقم زبون.');
            }
            $premium = $offer->package_type === 'premium';
            if ($premium) {
                $province = $subscriber['beinProvinceId'] ?: (string) $offer->bein_province_id;
                abort_unless($subscriber['firstName'] !== '' && $subscriber['lastName'] !== '' && ctype_digit($province) && collect($connection->bein_provinces ?? [])->contains(fn ($row): bool => $row['id'] === $province), 422, 'أدخل اسم المشترك والعائلة ومحافظة صحيحة من القائمة.');
                $subscriber['beinProvinceId'] = $province;
                $subscriber['phone'] = '+'.$mobile;
            } else {
                abort_if($subscriber['firstName'] !== '' || $subscriber['lastName'] !== '' || $subscriber['beinProvinceId'] !== '', 422, 'بيانات المشترك تخص اشتراك Premium فقط.');
            }
            $allocation = app(TopupAccounting::class)->reserve($connection, $own, $offer);
            $order = DigitalOrder::create(['connection_id' => $connection->id, 'offer_id' => $offer->id, 'account_id' => $own->id, 'main_account_id' => $allocation?->main_account_id ?? $connection->account_id, 'topup_grant_id' => $allocation?->id, 'admin_price_minor' => $allocation ? $offer->retail_minor : null, 'creator_id' => $actor->id, 'product_id' => $offer->product_id, 'provider' => $connection->provider, 'request_id' => $data['request_id'], 'request_hash' => $hash, 'remote_id' => $offer->remote_id, 'province_id' => $offer->province_id, 'type' => $offer->type, 'package_type' => $offer->package_type, 'mobile' => $mobile, 'subscriber' => $premium ? $subscriber : null, 'offer_version' => $offer->version, 'quoted_cost_minor' => $offer->cost_minor, 'quoted_retail_minor' => $retail, 'status' => 'pending', 'reservation_active' => true, 'version' => 1]);
            $this->audit->record('digital.create', $request, $actor, $own->id, ['order_id' => $order->id, 'connection_id' => $connection->id, 'provider' => $connection->provider, 'product_id' => $offer->product_id, 'status' => 'pending']);

            return $order;
        }, 5);
    }

    public function dispatch(User $actor, int $id, string $operation, Request $request, ?array $data = null, bool $refund = false): array
    {
        $permission = $refund ? 'digital.refund' : 'digital.create';
        $order = $this->access->order($actor, $id, $permission, ! $refund);
        if ($refund) {
            abort_unless($actor->membership->account->type === AccountType::System, 403);
        }
        $this->gateway->requireReady($order->connection, $operation === 'submit');
        abort_if($operation !== 'submit' && $this->gateway->directTopup($order->connection) && ($refund || $order->provider_purchase_response === null), 409, 'وثيقة المزود V2.1 لا تتضمن مسار استعلام أو استرجاع؛ راجع مرجع الشركة قبل أي عملية جديدة.');
        $dispatchKey = $operation === 'submit' ? 'submit:'.$id : $operation.':'.$actor->id.':'.$data['idempotency_key'];
        $payloadHash = Ledger::hash(['order_id' => $id, 'actor_id' => $actor->id, 'operation' => $operation, 'refund' => $refund, 'payload' => $data]);
        $attempt = DB::transaction(function () use ($actor, $id, $permission, $refund, $operation, $dispatchKey, $payloadHash, $data): ?ProviderAttempt {
            app(MutationGuard::class)->lock($actor, [], $operation === 'submit' ? 'digital.create' : 'app');
            $this->access->own($actor, $permission, true);
            $order = $this->access->order($actor, $id, $permission, ! $refund, true);
            $previous = ProviderAttempt::where('dispatch_key', $dispatchKey)->lockForUpdate()->first();
            if ($previous) {
                abort_unless($previous->order_id === $id && $previous->operation === $operation && hash_equals($previous->payload_hash, $payloadHash), 409, 'معرف التحقق مستخدم لعملية أو بيانات مختلفة.');

                return null;
            }
            if ($operation === 'submit' && $order->status !== 'pending') {
                return null;
            }
            if ($data) {
                $this->access->authority->version($order, $data['version']);
            }
            $refundable = $order->status === 'succeeded' || $this->gateway->directRabiaa($order->connection) && $order->status === 'review' && $order->provider_evidence !== null;
            abort_unless($refund ? $refundable : in_array($order->status, ['pending', 'review'], true), 409, 'العملية مكتملة ولا تحتاج إعادة تنفيذ.');
            $active = ProviderAttempt::where('active_order_id', $id)->lockForUpdate()->first();
            if ($active) {
                abort_unless($active->started_at->lt(now()->subSeconds(150)), 409, 'الطلب الحالي ما زال قيد الاتصال؛ انتظر ثم تحقق من نتيجته.');
                $active->update(['active_order_id' => null, 'status' => 'unknown', 'finished_at' => now()]);
            }

            return ProviderAttempt::create(['order_id' => $id, 'actor_id' => $actor->id, 'dispatch_key' => $dispatchKey, 'payload_hash' => $payloadHash, 'active_order_id' => $id, 'operation' => $operation, 'status' => 'dispatching', 'started_at' => now()]);
        }, 5);
        if (! $attempt) {
            return $this->dto($order->fresh(), $request);
        }
        $order->refresh();
        $payload = ['requestId' => $order->request_id];
        if ($refund && $this->gateway->directRabiaa($order->connection)) {
            $payload['_refund_check'] = true;
        }
        if ($operation === 'submit') {
            if ($this->gateway->directRabiaa($order->connection) || $this->gateway->directTopup($order->connection)) {
                $payload['_actor_session_version'] = $actor->session_version;
            }
            $payload += ['offerId' => (string) $order->offer_id, 'posId' => (string) $order->account_id, 'mobile' => $order->mobile ?? '', 'subscriber' => $order->subscriber, 'retail' => Money::decimal($order->quoted_retail_minor), 'provinceId' => $order->province_id, 'packageType' => $order->package_type];
            $payload += $order->provider === 'topup' ? ['category' => $order->remote_id, 'type' => $order->type] : ['catalogId' => (int) $order->remote_id];
        }
        try {
            $result = $this->gateway->call($order->connection, $operation === 'submit' ? 'submit' : 'verify', $payload);
        } catch (\Throwable) {
            $result = ['status' => 'review', 'message' => 'تعذر تأكيد النتيجة من الشركة؛ تحقق من الطلب نفسه دون إعادة شراء.'];
        }
        try {
            return $this->resolve($actor, $order, $attempt, $result, $request, $refund);
        } catch (ValidationException|UniqueConstraintViolationException) {
            return $this->resolve($actor, $order, $attempt, ['status' => 'review', 'message' => 'لم تؤكد الشركة نتيجة مطابقة للسعر أو المرجع؛ يلزم التحقق من الطلب نفسه.'], $request, $refund);
        }
    }

    private function resolve(User $actor, DigitalOrder $order, ProviderAttempt $attempt, array $result, Request $request, bool $refund): array
    {
        $status = $result['status'] ?? '';
        if (! in_array($status, ['succeeded', 'failed', 'review', 'refunded'], true)) {
            throw ValidationException::withMessages(['gateway' => 'حالة الشركة غير مؤكدة.']);
        }
        $values = [];
        if ($status === 'succeeded' || in_array($status, ['review', 'refunded'], true) && ($result['purchase_confirmed'] ?? false) === true) {
            $reference = $result['transactionId'] ?? null;
            $receiptRef = $result['receiptRef'] ?? null;
            if (! is_string($reference) || trim($reference) === '' || strlen($reference) > 190 || ! is_string($receiptRef) || trim($receiptRef) === '' || strlen($receiptRef) > 190) {
                throw ValidationException::withMessages(['gateway' => 'مرجع الشركة أو الوصل غير موجود.']);
            }
            $cost = DigitalMoney::minor($result['cost'] ?? null, 'cost');
            $retail = DigitalMoney::minor($result['retail'] ?? null, 'retail');
            if ($retail !== $order->quoted_retail_minor || isset($result['currency']) && $result['currency'] !== 'IQD' || $status === 'succeeded' && $order->package_type === 'premium' && ($result['beinStatus'] ?? null) !== 'success') {
                throw ValidationException::withMessages(['gateway' => 'قيمة البيع أو تفعيل المشترك غير مؤكد.']);
            }
            $basis = $result['cost_basis'] ?? 'provider_response';
            if (! in_array($basis, ['provider_response', 'catalog_snapshot'], true)) {
                throw ValidationException::withMessages(['gateway' => 'مصدر كلفة الشركة غير معروف.']);
            }
            $values = ['company_transaction_id' => $reference, 'receipt_ref' => $receiptRef, 'actual_cost_minor' => $cost, 'actual_retail_minor' => $retail, 'cost_basis' => $basis];
        }
        if ($status === 'refunded' && (! $refund || ! in_array($order->status, ['succeeded', 'review'], true) || ($order->actual_cost_minor ?? $values['actual_cost_minor'] ?? null) === null || ($result['transactionId'] ?? null) !== ($order->company_transaction_id ?? $values['company_transaction_id'] ?? null))) {
            throw ValidationException::withMessages(['gateway' => 'استرجاع الشركة لا يطابق عملية ناجحة مؤكدة.']);
        }
        $balance = isset($result['remaining_balance']) ? DigitalMoney::minor($result['remaining_balance'], 'remaining_balance', true) : null;

        return DB::transaction(function () use ($actor, $order, $attempt, $result, $request, $status, $values, $balance, $refund): array {
            $ids = DB::table('account_closure')->where('descendant_id', $order->account_id)->pluck('ancestor_id');
            Account::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $connection = DigitalConnection::whereKey($order->connection_id)->lockForUpdate()->firstOrFail();
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $attempt = ProviderAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($attempt->finished_at !== null) {
                return $this->dto($current, $request);
            }
            foreach ($values as $field => $value) {
                if ($current->$field !== null && $value !== $current->$field) {
                    throw ValidationException::withMessages(['gateway' => 'مرجع أو مبلغ العملية المؤكدة لا يتغير.']);
                }
            }
            $terminal = in_array($current->status, ['succeeded', 'failed', 'refunded'], true);
            $messages = ['succeeded' => 'أكدت الشركة نجاح العملية.', 'failed' => 'أكدت الشركة عدم نجاح العملية.', 'refunded' => 'أكدت الشركة استرجاع العملية.', 'review' => 'نتيجة الشركة غير مؤكدة؛ تحقق من الطلب نفسه دون إعادة شراء.'];
            $updates = ['message' => $messages[$status], 'version' => $current->version + 1];
            if (! $terminal || $refund && $status === 'refunded') {
                $updates += $values + ['status' => $status, 'reservation_active' => in_array($status, ['pending', 'review'], true)];
                if (in_array($status, ['succeeded', 'failed'], true) && ! $current->resolved_at) {
                    $updates['resolved_at'] = now();
                }
                if ($status === 'refunded') {
                    $updates['resolved_at'] = $current->resolved_at ?? now();
                    $updates['refunded_at'] = now();
                }
            }
            $current->update($updates);
            if (! $terminal || $refund && $status === 'refunded') {
                app(TopupAccounting::class)->resolve($current, $status);
                if ($balance === null && $current->topup_grant_id && $status === 'succeeded' && $connection->company_balance_minor !== null) {
                    $connection->update(['company_balance_minor' => max(0, $connection->company_balance_minor - $current->actual_cost_minor), 'balance_updated_at' => now()]);
                }
            }
            if ($balance !== null && in_array($status, ['succeeded', 'refunded'], true)) {
                $connection->update(['company_balance_minor' => $balance, 'balance_updated_at' => now()]);
            }
            $attempt->update(['active_order_id' => null, 'status' => $status === 'review' ? 'unknown' : 'received', 'result_status' => $status, 'response_hash' => Ledger::hash($result), 'finished_at' => now()]);
            $this->audit->record($refund ? 'digital.refund' : 'digital.result', $request, $actor, $current->account_id, ['order_id' => $current->id, 'attempt_id' => $attempt->id, 'status' => $current->status, 'company_transaction_id' => $current->company_transaction_id]);

            return $this->dto($current, $request);
        }, 5);
    }

    public function receipt(User $actor, int $id, Request $request): array
    {
        $this->access->require($actor, 'data.pin');
        $order = $this->access->order($actor, $id, 'digital.receipt', true);
        abort_unless($order->status === 'succeeded' && $order->receipt_ref, 409, 'الإيصال غير متاح قبل تأكيد نجاح الشركة.');
        if (! $order->receipt) {
            $result = $this->gateway->call($order->connection, 'receipt', ['receiptRef' => $order->receipt_ref, 'posId' => (string) $order->account_id]);
            $receipt = [];
            foreach (['code', 'serial'] as $field) {
                abort_unless(! isset($result[$field]) || is_string($result[$field]) && mb_strlen($result[$field]) <= 2000, 422, 'بيانات الوصل غير صحيحة.');
                $receipt[$field] = $result[$field] ?? '';
            }
            abort_if($order->provider === 'rabiaa' && $receipt['code'] === '', 422, 'لم يرجع رمز البطاقة الفعلي؛ لا يمكن عرض وصل فارغ.');
            DB::transaction(function () use ($actor, $order, $receipt): void {
                $current = $this->access->order($actor, $order->id, 'digital.receipt', true, true);
                abort_unless($current->status === 'succeeded', 409);
                if (! $current->receipt) {
                    $current->update(['receipt' => $receipt, 'version' => $current->version + 1]);
                }
            });
            $order->refresh();
        }
        $this->audit->record('digital.receipt', $request, $actor, $order->account_id, ['order_id' => $order->id]);

        return ['order' => $this->dto($order, $request), 'code' => $order->receipt['code'], 'serial' => $order->receipt['serial']];
    }

    public function printAuthorization(User $actor, int $id, Request $request): array
    {
        return DB::transaction(function () use ($actor, $id, $request): array {
            app(MutationGuard::class)->lock($actor, [], 'digital.print');
            $own = $this->access->own($actor, 'digital.receipt', true);
            app(OperationGuard::class)->assertAllowed($own->id, 'printing');
            $this->access->require($actor, 'data.pin');
            $order = $this->access->order($actor, $id, 'digital.receipt', true, true);
            abort_unless($order->status === 'succeeded', 409, 'الطباعة لعملية ناجحة مؤكدة فقط.');
            $settings = PrintPolicy::findOrFail(1)->settings;
            abort_unless($settings['printing_enabled'], 409, 'الطباعة موقوفة من الإدارة.');
            $this->devices->device($actor, $own, $request, $settings);
            $this->audit->record('digital.print_authorization', $request, $actor, $own->id, ['order_id' => $order->id]);

            return ['authorized' => true, 'expires_at' => now()->addSeconds(30)->toISOString()];
        });
    }
}
