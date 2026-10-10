<?php

namespace App\Services\Digital;

use App\Models\Digital\DigitalConnection;
use App\Services\Finance\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ProviderGateway
{
    public function directTopup(?DigitalConnection $connection): bool
    {
        return $connection?->provider === 'topup' && config('digital.topup.driver') === 'masal_v2_1';
    }

    public function directRabiaa(?DigitalConnection $connection): bool
    {
        return $connection?->provider === 'rabiaa' && config('digital.rabiaa.driver') === 'nojoom_v2_1';
    }

    public function status(?DigitalConnection $connection = null, bool $requireCredential = true): array
    {
        $missing = [];
        $direct = $this->directTopup($connection);
        $rabiaa = $this->directRabiaa($connection);
        $prefix = $direct ? 'digital.topup' : ($rabiaa ? 'digital.rabiaa' : 'digital');
        $url = (string) config($prefix === 'digital' ? 'digital.gateway_url' : $prefix.'.url');
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array(strtolower($parts['host']), config($prefix.'.allowed_hosts', []), true)) {
            $missing[] = 'عنوان بوابة HTTPS معتمد ضمن قائمة الخادم';
        }
        if (! $direct && ! $rabiaa && ! config('digital.contract_verified')) {
            $missing[] = 'وثائق وتأكيد عقد الربط الفعلي للشركة';
        }
        if ($connection && $requireCredential && ! $connection->credential) {
            $missing[] = 'لم يُضف توكن الشركة بعد';
        }

        return ['configured' => ! $missing, 'purchases_enabled' => (bool) config('digital.purchases_enabled'), 'missing' => $missing, 'contract' => config($prefix.'.contract'), 'verification_supported' => ! $direct, 'refund_verification_supported' => ! $direct, 'balance_supported' => ! $rabiaa];
    }

    public function requireReady(DigitalConnection $connection, bool $purchase = false): void
    {
        $status = $this->status($connection);
        abort_unless($status['configured'], 503, 'الخدمة غير متصلة: '.implode('، ', $status['missing']).'.');
        abort_if($purchase && ! $status['purchases_enabled'], 409, 'تنفيذ المشتريات الخارجية غير مفعّل بعد اعتماد الربط الفعلي.');
    }

    public function call(DigitalConnection $connection, string $operation, array $payload = []): array
    {
        $this->requireReady($connection, $operation === 'submit');
        abort_unless(in_array($operation, ['catalog', 'inventory', 'submit', 'verify', 'receipt'], true), 500);
        if ($this->directTopup($connection)) {
            return app(MasalTopupAdapter::class)->call($connection, $operation, $payload);
        }
        if ($this->directRabiaa($connection)) {
            return app(NojoomRabiaaAdapter::class)->call($connection, $operation, $payload);
        }
        $base = rtrim((string) config('digital.gateway_url'), '/');

        return $this->transport($base, $operation, 'POST', [], $payload + ['provider' => $connection->provider, 'connectionId' => (string) $connection->id, 'credential' => $connection->credential, 'contract' => config('digital.contract')]);
    }

    public function transport(string $base, string $path, string $method, array $headers, array $payload = [], bool $exactMoney = false, bool $scalarBalance = false): array
    {
        $parts = parse_url($base);
        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        $safe = array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
        abort_unless($safe && count($safe) === count($ips), 503, 'عنوان الربط يجب أن يكون وجهة عامة معتمدة.');
        $port = $parts['port'] ?? 443;
        $options = ['allow_redirects' => false];
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.reset($safe)]];
        }
        $response = Http::acceptJson()->connectTimeout(8)->timeout(25)->withOptions($options)->withHeaders($headers)->send($method, rtrim($base, '/').'/'.ltrim($path, '/'), $method === 'GET' ? ['query' => $payload] : ['json' => $payload]);
        if (! $response->successful()) {
            throw new ProviderRejection($response->status());
        }
        if (strlen($response->body()) > 2097152) {
            throw ValidationException::withMessages(['gateway' => 'لم يؤكد خادم الشركة نتيجة صحيحة؛ يلزم التحقق قبل إعادة أي شراء.']);
        }
        $body = $response->body();
        if ($exactMoney) {
            $body = preg_replace_callback('/"(?:\\\\.|[^"\\\\])*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', fn (array $match): string => strpbrk($match[0], '.eE') === false ? $match[0] : json_encode($match[0], JSON_THROW_ON_ERROR), $body);
        }
        try {
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['gateway' => 'استجابة الشركة ليست JSON صحيحًا.']);
        }
        if ($scalarBalance && (is_int($result) || is_string($result))) {
            return ['remaining_balance' => Money::decimal(DigitalMoney::minor($result, 'remaining_balance', true))];
        }
        if (! is_array($result) || array_is_list($result)) {
            throw ValidationException::withMessages(['gateway' => 'استجابة الشركة لا تطابق عقد الربط المعتمد.']);
        }

        return $result;
    }
}
