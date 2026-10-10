<?php

namespace App\Services\Digital;

use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Sales\PrintPolicy;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class NojoomRabiaaAdapter
{
    public function __construct(private ProviderGateway $gateway, private DigitalAccess $access) {}

    private function request(DigitalConnection $connection, string $path, string $method = 'GET', array $payload = []): array
    {
        return $this->gateway->transport(rtrim((string) config('digital.rabiaa.url'), '/'), 'vendor/'.$path, $method, ['X-API-Key' => $connection->credential], $payload, true);
    }

    private function rows(array $result, int $maximum): array
    {
        $rows = $result['data'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= $maximum, 422, 'استجابة الرابعة لا تطابق القائمة الموثقة.');

        return $rows;
    }

    public function call(DigitalConnection $connection, string $operation, array $payload): array
    {
        if ($operation === 'catalog') {
            $rows = [];
            foreach ($this->rows($this->request($connection, 'provinces'), 200) as $province) {
                abort_unless(is_array($province) && ctype_digit((string) ($province['id'] ?? '')) && (int) $province['id'] > 0, 422);
                foreach ($this->rows($this->request($connection, 'catalog', 'GET', ['provinceId' => (int) $province['id']]), 5000) as $row) {
                    abort_unless(is_array($row), 422);
                    $rows[] = $row + ['provinceId' => (int) $province['id']];
                    abort_if(count($rows) > 5000, 422, 'قائمة الرابعة تتجاوز الحد المسموح.');
                }
            }
            $provinces = [];
            if (collect($rows)->contains(fn ($row): bool => ($row['packageType'] ?? '') === 'premium')) {
                $provinces = $this->rows($this->request($connection, 'bein/provinces'), 200);
            }

            return ['data' => $rows, 'beinProvinces' => $provinces];
        }
        if ($operation === 'inventory') {
            abort(409, 'وثيقة الرابعة تعرض إحصاءات المبيعات ولا تتضمن مسارًا لرصيد مالي متبقٍ؛ لا يمكن اعتبار إجمالي المبيعات رصيدًا.');
        }
        if ($operation === 'receipt') {
            abort(409, 'رمز الرابعة يعاد عند الشراء فقط؛ يُقرأ الإيصال المحفوظ والمشفر دون إعادة شراء.');
        }
        $order = DigitalOrder::where('connection_id', $connection->id)->where('request_id', $payload['requestId'] ?? '')->firstOrFail();
        if ($operation === 'verify') {
            return $this->verify($connection, $order, ($payload['_refund_check'] ?? false) === true);
        }
        abort_unless($operation === 'submit', 500);

        return $this->purchase($connection, $order, $payload);
    }

    private function purchase(DigitalConnection $connection, DigitalOrder $order, array $payload): array
    {
        // A dispatch is persisted before this method. Never create a second hold for the same request.
        if ($order->provider_hold || $order->provider_purchase_started_at || $order->provider_evidence) {
            return ['status' => 'review'];
        }
        try {
            $catalog = $this->rows($this->request($connection, 'catalog', 'GET', ['provinceId' => (int) $order->province_id]), 5000);
            $offer = collect($catalog)->first(fn ($row): bool => is_array($row) && (string) ($row['catalogId'] ?? '') === $order->remote_id);
            if (! $offer || ($offer['inStock'] ?? null) !== true || ($offer['packageType'] ?? '') !== $order->package_type || DigitalMoney::minor($offer['vendorPrice'] ?? null, 'cost') !== $order->quoted_cost_minor) {
                return ['status' => 'failed'];
            }
        } catch (\Throwable) {
            return ['status' => 'failed'];
        }
        $input = ['catalogId' => (int) $order->remote_id];
        if ($order->package_type === 'premium') {
            $input += $order->subscriber;
            $input['beinProvinceId'] = (int) $input['beinProvinceId'];
        }
        try {
            $hold = $this->request($connection, 'hold', 'POST', $input)['data'] ?? null;
        } catch (ProviderRejection $error) {
            if (in_array($error->status, [400, 401, 403, 404, 422], true)) {
                return ['status' => 'failed'];
            }
            throw $error;
        }
        abort_unless(is_array($hold) && is_string($hold['holdToken'] ?? null) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $hold['holdToken']) && ctype_digit((string) ($hold['cardId'] ?? '')) && (int) $hold['cardId'] > 0 && is_int($hold['expiresInMinutes'] ?? null) && $hold['expiresInMinutes'] > 0 && is_string($hold['heldAt'] ?? null), 422, 'لم ترجع الرابعة حجزًا موثقًا صالحًا.');
        $heldAt = CarbonImmutable::parse($hold['heldAt']);
        $hold = ['holdToken' => $hold['holdToken'], 'cardId' => (int) $hold['cardId'], 'heldAt' => $heldAt->toISOString(), 'expiresAt' => $heldAt->addMinutes($hold['expiresInMinutes'])->toISOString(), 'expiresInMinutes' => $hold['expiresInMinutes'], 'vendorPrice' => Money::decimal(DigitalMoney::minor($hold['vendorPrice'] ?? null, 'cost'))];
        DB::transaction(function () use ($order, $hold): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($current->provider_hold !== null, 409);
            $current->update(['provider_hold' => $hold, 'version' => $current->version + 1]);
        });
        if (DigitalMoney::minor($hold['vendorPrice']) !== $order->quoted_cost_minor || CarbonImmutable::parse($hold['expiresAt'])->lte(now())) {
            return ['status' => 'failed'];
        }
        try {
            DB::transaction(function () use ($order, $connection, $payload): void {
                $actor = $order->creator;
                $actor->session_version = $payload['_actor_session_version'];
                app(MutationGuard::class)->lock($actor, [$order->account_id], 'digital.create');
                $own = $this->access->own($actor, 'digital.create', true);
                abort_unless(PrintPolicy::findOrFail(1)->settings['sales_enabled'], 409);
                $currentConnection = $this->access->connection($actor, $connection->id, 'digital.create', true);
                abort_unless($this->access->offers($currentConnection, $own)->contains('id', $order->offer_id), 403);
                $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_if($current->provider_purchase_started_at !== null, 409);
                $current->update(['provider_purchase_started_at' => now(), 'version' => $current->version + 1]);
            });
        } catch (\Throwable) {
            return ['status' => 'failed'];
        }
        // The provider consumes holdToken. A timeout is reconciled by history, never by another purchase.
        $purchase = $this->request($connection, 'purchase', 'POST', ['holdToken' => $hold['holdToken']])['data'] ?? null;
        abort_unless(is_array($purchase), 422);
        DB::transaction(function () use ($order, $purchase): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($current->provider_purchase_response !== null, 409);
            $current->update(['provider_purchase_response' => $purchase, 'version' => $current->version + 1]);
        });
        abort_unless(is_array($purchase) && (string) ($purchase['cardId'] ?? '') === (string) $hold['cardId'] && ctype_digit((string) ($purchase['transactionId'] ?? '')) && (int) $purchase['transactionId'] > 0 && is_string($purchase['code'] ?? null) && $purchase['code'] !== '' && mb_strlen($purchase['code']) <= 2000 && is_string($purchase['serialNumber'] ?? null) && mb_strlen($purchase['serialNumber']) <= 2000, 422, 'شراء الرابعة يحتاج مرجعًا ورمزًا وسيريال مطابقًا للحجز.');
        $receipt = ['code' => $purchase['code'], 'serial' => $purchase['serialNumber']];
        $response = array_intersect_key($purchase, array_flip(['cardId', 'transactionId', 'vendorPrice', 'publicPrice', 'beinStatus', 'soldAt']));
        DB::transaction(function () use ($order, $receipt): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($current->receipt !== null, 409);
            $current->update(['receipt' => $receipt, 'version' => $current->version + 1]);
        });
        abort_unless(collect($response)->every(fn ($value): bool => $value === null || (is_scalar($value) && strlen((string) $value) <= 2000)), 422);
        $evidence = ['cardId' => $hold['cardId'], 'transactionId' => (string) $purchase['transactionId'], 'cost' => Money::decimal(DigitalMoney::minor($purchase['vendorPrice'] ?? null, 'cost')), 'publicPrice' => Money::decimal(DigitalMoney::minor($purchase['publicPrice'] ?? null, 'public_price')), 'beinStatus' => $purchase['beinStatus'] ?? null];
        DB::transaction(function () use ($order, $evidence): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($current->provider_evidence !== null, 409);
            $current->update(['provider_evidence' => $evidence, 'version' => $current->version + 1]);
        });

        return $this->result($order->fresh(), $evidence);
    }

    private function result(DigitalOrder $order, array $evidence): array
    {
        $confirmed = $order->receipt !== null && ($order->package_type !== 'premium' || ($evidence['beinStatus'] ?? null) === 'success');

        return ['status' => $confirmed ? 'succeeded' : 'review', 'purchase_confirmed' => true, 'transactionId' => (string) $evidence['transactionId'], 'receiptRef' => (string) $evidence['transactionId'], 'cost' => $evidence['cost'], 'retail' => Money::decimal($order->quoted_retail_minor), 'cost_basis' => 'provider_response', 'currency' => 'IQD', 'beinStatus' => $evidence['beinStatus'] ?? null];
    }

    private function verify(DigitalConnection $connection, DigitalOrder $order, bool $refundCheck): array
    {
        $hold = $order->provider_hold;
        if (! $hold || ! $order->provider_purchase_started_at) {
            return ['status' => 'review'];
        }
        $page = 1;
        $history = [];
        do {
            $response = $this->request($connection, 'transactions', 'GET', ['page' => $page, 'pageSize' => 100, 'dateFrom' => CarbonImmutable::parse($hold['heldAt'])->utc()->toDateString(), 'dateTo' => now()->utc()->toDateString()]);
            $last = $response['meta']['pagination']['pageCount'] ?? null;
            abort_unless(is_int($last) && $last >= 1 && $last <= 100, 422, 'سجل الرابعة يتجاوز نطاق التحقق؛ يلزم مراجعة الشركة.');
            $history = array_merge($history, $this->rows($response, 100));
            $page++;
        } while ($page <= $last);
        foreach ($history as $row) {
            if (! is_array($row) || ($row['type'] ?? '') !== 'purchase' || (string) ($row['cardId'] ?? '') !== (string) $hold['cardId'] || (string) ($row['catalogId'] ?? '') !== $order->remote_id || ! is_string($row['createdAt'] ?? null) || CarbonImmutable::parse($row['createdAt'])->lt(CarbonImmutable::parse($hold['heldAt'])) || $order->provider_evidence && (string) ($row['id'] ?? '') !== $order->provider_evidence['transactionId']) {
                continue;
            }
            // History can confirm the charged money, but cannot recover a PIN lost in a purchase timeout.
            if (! $order->provider_evidence) {
                abort_unless(ctype_digit((string) ($row['id'] ?? '')) && (int) $row['id'] > 0, 422);
                $evidence = ['cardId' => $hold['cardId'], 'transactionId' => (string) $row['id'], 'cost' => Money::decimal(DigitalMoney::minor($row['vendorPrice'] ?? null, 'cost')), 'publicPrice' => Money::decimal(DigitalMoney::minor($row['publicPrice'] ?? null, 'public_price')), 'beinStatus' => $row['beinStatus'] ?? null];
                DB::transaction(function () use ($order, $evidence): void {
                    $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                    if (! $current->provider_evidence) {
                        $current->update(['provider_evidence' => $evidence, 'version' => $current->version + 1]);
                    }
                });
                $order->refresh();
            }
            abort_unless(ctype_digit((string) ($row['id'] ?? '')) && DigitalMoney::minor($row['vendorPrice'] ?? null, 'cost') === DigitalMoney::minor($order->provider_evidence['cost']), 422);
            if (is_array($row['refundIds'] ?? null) && $row['refundIds'] !== []) {
                foreach ($history as $refund) {
                    if (is_array($refund) && ($refund['type'] ?? '') === 'refund' && in_array($refund['id'] ?? null, $row['refundIds'], true) && (string) ($refund['refundOfId'] ?? '') === (string) $row['id'] && (string) ($refund['cardId'] ?? '') === (string) $hold['cardId'] && str_starts_with((string) ($refund['vendorPrice'] ?? ''), '-') && DigitalMoney::minor(substr((string) $refund['vendorPrice'], 1), 'refund') === DigitalMoney::minor($order->provider_evidence['cost'])) {
                        return array_replace($this->result($order, $order->provider_evidence), ['status' => $refundCheck ? 'refunded' : 'review', 'company_refund_found' => true]);
                    }
                }

                return ['status' => 'review'];
            }

            return $this->result($order, array_replace($order->provider_evidence, ['beinStatus' => $row['beinStatus'] ?? null]));
        }

        return ['status' => 'review'];
    }
}
