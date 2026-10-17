<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\User;
use App\Services\Digital\DigitalAccess;
use App\Services\Operations\MutationGuard;
use App\Services\Sales\PosLoginDevice;
use App\Services\Sales\SalesAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

$base = '/home/dananiriq/masal-verification/finance-stock-20261006-01';
$directory = $argv[1] ?? '';
$login = $argv[2] ?? '';
$mode = $argv[3] ?? '';
$targetId = (int) ($argv[4] ?? 0);
$requestId = $argv[5] ?? '';
if (getenv('MASAL_POINT_WRITE_LOCK_NATIVE') !== '1' || realpath($base) !== $base || is_link($directory)
    || realpath(dirname($directory)) !== '/home/dananiriq/masal-verification/audit84-20261014'
    || ! preg_match('/^point-lock-[a-f0-9-]{36}$/D', basename($directory)) || realpath($directory) !== $directory
    || ! in_array($login, ['scale-point-1', 'scale-point-2', 'scale-agent-1'], true) || ! in_array($mode, ['point', 'stock', 'funding', 'insert', 'login-binding'], true)
    || $mode === 'insert' && ! preg_match('/^zz-native-gap-[a-f0-9-]{36}-b$/D', $requestId)) {
    throw new RuntimeException('Explicit isolated point worker context is required.');
}
require $base.'/vendor/autoload.php';
putenv('APP_CONFIG_CACHE='.$base.'/tests/Support/nonexistent-config-cache.php');
putenv('APP_ROUTES_CACHE='.$base.'/tests/Support/nonexistent-routes-cache.php');
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify' || realpath(storage_path()) !== $base.'/storage') {
    throw new RuntimeException('Point worker cannot use another environment or database.');
}
$app->instance('request', Request::create('/api/v1/digital/orders', 'POST'));
DB::statement('SET SESSION innodb_lock_wait_timeout=5');
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::beginTransaction();
$result = ['ok' => false, 'status' => 0];
try {
    $actor = User::where('login', $login)->firstOrFail();
    if (! str_ends_with($actor->email, '@example.invalid') || $actor->membership->kind !== 'owner'
        || $actor->membership->account->type !== ($mode === 'funding' ? AccountType::MainAgent : AccountType::Pos)) {
        throw new RuntimeException('Only the intended synthetic owner can enter the point worker.');
    }
    $actor->isOperational();
    touch($directory.'/ready');
    $deadline = microtime(true) + 8;
    while (! is_file($directory.'/go')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Point worker start barrier timed out.');
        }
        usleep(10000);
    }
    touch($directory.'/trying');
    if ($mode === 'login-binding') {
        Account::whereKey($actor->membership->account_id)->lockForUpdate()->firstOrFail();
        $device = DB::table('pos_registered_devices')->where('account_id', $actor->membership->account_id)->where('id', $targetId)->first();
        if (! $device || $device->model !== 'Synthetic native login second' || empty($device->proof_public_key)) {
            throw new RuntimeException('Only the synthetic second enrollment fixture may attempt the native login binding.');
        }
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $result['login_allowed'] = app(PosLoginDevice::class)->acceptHeartbeat($actor->membership->account_id, $device, hash('sha256', $device->proof_public_key), $request);
    } elseif ($mode === 'stock') {
        app(MutationGuard::class)->lock($actor, [], 'sales.create', true);
        app(SalesAccess::class)->own($actor, 'sell.create', true, true);
    } elseif ($mode !== 'funding') {
        app(MutationGuard::class)->lock($actor, [], 'digital.create', true);
        app(DigitalAccess::class)->own($actor, 'digital.create', true);
        if ($mode === 'insert') {
            $source = DigitalOrder::where('account_id', $actor->membership->account_id)->where('connection_id', $targetId)->where('status', 'succeeded')->firstOrFail();
            $connection = DigitalConnection::whereKey($targetId)->firstOrFail();
            if ($connection->provider !== 'topup' || ! str_starts_with($connection->credential, 'isolated-qapress-')) {
                throw new RuntimeException('Only the isolated synthetic Topup connection may be used by the gap probe.');
            }
            DigitalConnection::whereKey($targetId)->lockForUpdate()->firstOrFail();
            touch($directory.'/connection');
            $deadline = microtime(true) + 8;
            while (! is_file($directory.'/go-insert')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Point worker insert barrier timed out.');
                }
                usleep(10000);
            }
            $copy = $source->replicate();
            $copy->forceFill(['request_id' => $requestId, 'request_hash' => hash('sha256', $requestId), 'status' => 'pending', 'reservation_active' => true, 'version' => 1,
                'acknowledged_at' => null, 'accounted_at' => null, 'company_transaction_id' => null, 'receipt_ref' => null, 'receipt' => null, 'actual_cost_minor' => null,
                'actual_retail_minor' => null, 'cost_basis' => null, 'resolved_at' => null, 'refunded_at' => null, 'provider_hold' => null, 'provider_evidence' => null,
                'provider_purchase_response' => null, 'provider_purchase_started_at' => null]);
            touch($directory.'/inserting');
            $copy->save();
            $result['inserted_before_rollback'] = true;
        }
    } else {
        app(MutationGuard::class)->lock($actor, [$targetId], 'wallets.transfer');
    }
    touch($directory.'/acquired');
    $result['ok'] = true;
} catch (HttpException $failure) {
    $result['status'] = $failure->getStatusCode();
} catch (QueryException $failure) {
    $result['driver_error'] = (int) ($failure->errorInfo[1] ?? 0);
} catch (Throwable $failure) {
    $result['error_class'] = get_class($failure);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    file_put_contents($directory.'/result.json', json_encode($result, JSON_THROW_ON_ERROR));
    chmod($directory.'/result.json', 0600);
}
