<?php

use App\Services\Finance\Ledger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$base = '/home/dananiriq/masal-verification/finance-stock-20261006-01';
$directory = $argv[1] ?? '';
$slot = $argv[2] ?? '';
$accountId = (int) ($argv[3] ?? 0);
$service = $argv[4] ?? '';
$kind = $argv[5] ?? '';
$mode = $argv[6] ?? '';
if (getenv('MASAL_WALLET_LOOKUP_NATIVE') !== '1' || realpath($base) !== $base || realpath(dirname($directory)) !== '/home/dananiriq/masal-verification/audit-rollup86-20261014'
    || ! preg_match('/^wallet-native-[a-f0-9-]{36}$/D', basename($directory)) || realpath($directory) !== $directory || ! in_array($slot, ['0', '1'], true)
    || $accountId < 1 || ! in_array($service, ['cash', 'voucher'], true) || ! in_array($kind, ['account', 'external'], true) || ! in_array($mode, ['existing', 'missing'], true)) {
    throw new RuntimeException('Explicit disposable wallet lookup worker context is required.');
}
require $base.'/vendor/autoload.php';
putenv('APP_CONFIG_CACHE='.$base.'/tests/Support/nonexistent-config-cache.php');
putenv('APP_ROUTES_CACHE='.$base.'/tests/Support/nonexistent-routes-cache.php');
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify' || realpath(storage_path()) !== $base.'/storage') {
    throw new RuntimeException('Wallet worker cannot use another environment or database.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout=8');
$wait = static function (callable $condition): void {
    $deadline = microtime(true) + 10;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Native wallet synchronization timed out.');
        }
        usleep(10000);
    }
};
$queries = 0;
$inserts = 0;
$barrierUsed = false;
DB::listen(function (QueryExecuted $query) use (&$queries, &$inserts, &$barrierUsed, $directory, $slot, $mode, $wait): void {
    if (! str_contains($query->sql, 'finance_wallets')) {
        return;
    }
    $queries++;
    $insert = str_starts_with(strtolower($query->sql), 'insert ignore');
    $inserts += $insert ? 1 : 0;
    if ($barrierUsed || ! (($mode === 'existing' && $insert) || ($mode === 'missing' && $queries === 1 && str_starts_with(strtolower($query->sql), 'select')))) {
        return;
    }
    $barrierUsed = true;
    $prefix = $mode === 'existing' ? 'insert' : 'missing';
    touch($directory.'/'.$prefix.'-'.$slot);
    $wait(fn () => is_file($directory.'/'.$prefix.'-0') && is_file($directory.'/'.$prefix.'-1'));
});
touch($directory.'/ready-'.$slot);
$wait(fn () => is_file($directory.'/go'));
$attempts = 0;
$result = ['ok' => false, 'wallet_id' => null, 'driver_error' => null];
try {
    if ($mode === 'missing') {
        $wallet = DB::transaction(function () use (&$attempts, $accountId, $service, $kind) {
            $attempts++;

            return app(Ledger::class)->wallet($accountId, $service, 'IQD', $kind);
        }, 5);
    } else {
        DB::beginTransaction();
        $attempts++;
        $wallet = app(Ledger::class)->wallet($accountId, $service, 'IQD', $kind);
        usleep(150000);
    }
    $result = ['ok' => true, 'wallet_id' => $wallet->id, 'driver_error' => null];
} catch (QueryException $failure) {
    $result['driver_error'] = (int) ($failure->errorInfo[1] ?? 0);
} catch (Throwable $failure) {
    $result['error_class'] = get_class($failure);
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result += ['attempts' => $attempts, 'duplicate_inserts' => $inserts, 'wallet_queries' => $queries];
    file_put_contents($directory.'/result-'.$slot.'.json', json_encode($result, JSON_THROW_ON_ERROR));
    chmod($directory.'/result-'.$slot.'.json', 0600);
}
