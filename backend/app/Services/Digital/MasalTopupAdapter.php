<?php

namespace App\Services\Digital;

use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Sales\PrintPolicy;
use App\Services\Finance\Money;
use App\Services\Operations\MutationGuard;
use App\Services\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasalTopupAdapter
{
    public function __construct(private ProviderGateway $gateway) {}

    private function request(DigitalConnection $connection, string $path, string $method = 'GET', array $payload = []): array
    {
        return $this->gateway->transport(rtrim((string) config('digital.topup.url'), '/'), 'api/v1/'.$path, $method, ['x-api-key' => $connection->credential], $payload, true, $path === 'inventory' && $method === 'GET');
    }

    private function normalizeCatalog(array $result): array
    {
        if (($result['status'] ?? null) !== 'ok' || ! is_array($result['products'] ?? null)) {
            throw ValidationException::withMessages(['gateway' => 'لم يرجع المزود قائمة الفئات المعتمدة.']);
        }
        $result['products'] = array_values(array_filter($result['products'], fn ($row): bool => is_array($row) && (! array_key_exists('show2site', $row) || (int) $row['show2site'] === 1) && in_array($row['type'] ?? null, ['topup', 'bundle', 'bill'], true)));

        return array_merge($result, app(MasalCatalog::class)->normalize($result['products']));
    }

    public function call(DigitalConnection $connection, string $operation, array $payload): array
    {
        if ($operation === 'catalog') {
            return $this->normalizeCatalog($this->request($connection, 'products'));
        }
        if ($operation === 'inventory') {
            return $this->request($connection, 'inventory');
        }
        if ($operation === 'verify') {
            $order = DigitalOrder::where('connection_id', $connection->id)->where('request_id', $payload['requestId'])->firstOrFail();

            return $this->purchaseResult($connection, $order, $order->provider_purchase_response ?? []);
        }
        if ($operation === 'receipt') {
            // V2.1 returns the transaction reference and recipient, and documents no PIN endpoint.
            return ['code' => '', 'serial' => ''];
        }
        if ($operation !== 'submit') {
            throw ValidationException::withMessages(['gateway' => 'هذا المسار غير موثق في واجهة المزود.']);
        }
        $order = DigitalOrder::where('connection_id', $connection->id)->where('request_id', $payload['requestId'])->firstOrFail();
        if ($order->manual_category_name) {
            return ['status' => 'failed'];
        }
        $input = ['mobile' => $order->mobile, 'type' => $order->type, 'category' => $order->remote_id];
        // These checks occur before the only financial POST; their failure cannot represent a purchase.
        try {
            $preflight = $this->gateway->preflight(rtrim((string) config('digital.topup.url'), '/'), ['x-api-key' => $connection->credential], [
                'catalog' => ['path' => 'api/v1/products', 'method' => 'GET', 'payload' => []],
                'eligibility' => ['path' => 'api/v1/checkEligibility', 'method' => 'POST', 'payload' => $input],
            ]);
            $catalog = $this->normalizeCatalog($preflight['catalog']);
            $category = collect($catalog['catalog'])->first(fn ($row): bool => $row['remote_id'] === $order->remote_id && $row['type'] === $order->type);
            if (! $category || DigitalMoney::minor($category['cost'], 'cost') !== $order->quoted_cost_minor) {
                return ['status' => 'failed'];
            }
            $eligibility = $preflight['eligibility'];
            if (($eligibility['status'] ?? null) !== true) {
                return ['status' => 'failed'];
            }
        } catch (\Throwable) {
            return ['status' => 'failed'];
        }
        try {
            DB::transaction(function () use ($connection, $order, $payload): void {
                $actor = $order->creator;
                $actor->session_version = $payload['_actor_session_version'];
                app(MutationGuard::class)->lock($actor, [$order->account_id], 'digital.create', shareAncestors: true);
                $access = app(DigitalAccess::class);
                $own = $access->own($actor, 'digital.create', true);
                $settings = PrintPolicy::findOrFail(1)->settings;
                abort_unless($settings['sales_enabled'], 409);
                app(SalesAccess::class)->device($actor, $own, request(), $settings);
                [$currentConnection, $offer] = $access->saleOffer($actor, $own, $connection->id, $order->offer_id);
                $this->gateway->requireReady($currentConnection, true);
                abort_unless(hash_equals(DigitalConfiguration::credentialHash($connection->credential), DigitalConfiguration::credentialHash($currentConnection->credential)), 409);
                abort_unless($offer && $offer->version === $order->offer_version && $offer->cost_minor === $order->quoted_cost_minor && ($order->topup_grant_id ? $offer->retail_minor === $order->admin_price_minor : $offer->retail_minor === $order->quoted_retail_minor) && ($offer->topup_category_id !== null || $access->catalog->products($actor)->whereKey($offer->product_id)->exists()), 403);
                $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                abort_if($current->provider_purchase_started_at !== null, 409);
                $current->update(['provider_purchase_started_at' => now(), 'version' => $current->version + 1]);
            });
        } catch (\Throwable) {
            return ['status' => 'failed'];
        }
        // V2.1 has no documented idempotency header or status lookup: never automatically retry this POST.
        $result = $this->request($connection, 'transactions', 'POST', $input + ['user_id' => (string) $order->creator_id, 'user_name' => $order->creator->name, 'agent_name' => $order->mainAccount->name]);
        DB::transaction(function () use ($order, $result): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            abort_if($current->provider_purchase_response !== null, 409);
            $current->update(['provider_purchase_response' => $result, 'version' => $current->version + 1]);
        });

        return $this->purchaseResult($connection, $order, $result);
    }

    private function purchaseResult(DigitalConnection $connection, DigitalOrder $order, array $result): array
    {
        $success = in_array(strtolower((string) ($result['status'] ?? '')), ['success', 'succeed'], true);
        if (! $success) {
            return ['status' => in_array((string) ($result['code'] ?? ''), ['101', '103'], true) && in_array((string) ($result['status'] ?? ''), ['400', '401'], true) ? 'failed' : 'review'];
        }
        $details = $result['data'] ?? $result;
        if (! is_array($details) || isset($result['data']) && ! in_array(strtolower((string) ($details['status'] ?? '')), ['success', 'succeed'], true)) {
            return ['status' => 'review'];
        }
        if (isset($details['logicalResource']['value'])) {
            try {
                if (DigitalMoney::phone((string) $details['logicalResource']['value']) !== $order->mobile) {
                    return ['status' => 'review'];
                }
            } catch (ValidationException) {
                return ['status' => 'review'];
            }
        }
        $reference = $details['id'] ?? null;
        if (is_int($reference) && $reference > 0) {
            $reference = (string) $reference;
        }
        if (! is_string($reference) || $reference === '' || strlen($reference) > 190) {
            return ['status' => 'review'];
        }
        DB::transaction(function () use ($order, $reference): void {
            $current = DigitalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $current->update(['provider_evidence' => ['transactionId' => $reference, 'status' => 'succeeded'], 'version' => $current->version + 1]);
        });
        $cost = Money::decimal($order->quoted_cost_minor);
        $basis = 'catalog_snapshot';

        // V2.1 totalAmount describes the customer payment, not documented agent settlement.
        return ['status' => 'succeeded', 'transactionId' => $reference, 'receiptRef' => $reference, 'cost' => $cost, 'retail' => Money::decimal($order->quoted_retail_minor), 'cost_basis' => $basis, 'currency' => 'IQD'];
    }
}
