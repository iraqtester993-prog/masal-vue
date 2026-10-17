<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
define('LARAVEL_START', microtime(true));
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__);
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify') {
    http_response_code(403);
    exit;
}
config(['session.driver' => 'file', 'session.secure' => false, 'maps.require_location' => false, 'digital.topup.driver' => 'masal_v2_1', 'digital.purchases_enabled' => true]);
Http::preventStrayRequests();
Http::fake(function ($request) {
    if (str_ends_with($request->url(), '/inventory')) {
        // Exercise local settlement when the provider balance endpoint is unavailable.
        return Http::response(['message' => 'Synthetic balance unavailable after purchase'], 503);
    }
    if (str_ends_with($request->url(), '/products')) {
        return Http::response(['status' => 'ok', 'products' => [['id' => 49, 'title' => '6000', 'type' => 'topup', 'price' => 6000, 'show2site' => 1]]]);
    }
    if (str_ends_with($request->url(), '/checkEligibility')) {
        return Http::response(['status' => true]);
    }
    $delay = (int) (getenv('MASAL_SCALE_PROVIDER_DELAY_MS') ?: 100);
    if (! in_array($delay, [100, 1000], true)) {
        throw new RuntimeException('Unsupported provider test delay');
    }
    usleep($delay * 1000);

    return Http::response(['id' => 'scale-provider-'.Str::uuid(), 'status' => 'Succeed']);
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
