<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Digital\DigitalConnection;
use App\Models\Digital\DigitalOrder;
use App\Models\Operations\DirectStop;
use App\Models\User;
use App\Services\Digital\DigitalAccess;
use App\Services\Operations\MutationGuard;
use App\Services\Sales\PosLoginDevice;
use App\Services\Sales\SalesAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class PointWriteLockNativeTest extends TestCase
{
    use CreatesAccounts;

    private function prepareEmptyPointFixture(): void
    {
        if (getenv('MASAL_NATIVE_PREPARE_POINTS') !== '1' || Account::exists()) {
            return;
        }
        $this->assertSame(0, User::count(), 'An empty native point fixture must not contain existing users.');
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries', 'finance_operations', 'finance_invoices', 'topup_grants', 'topup_wallet_bindings', 'topup_finance_events', 'digital_connections', 'digital_orders', 'digital_provider_attempts'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Native preparation cannot mix synthetic actors with existing financial facts.');
        }
        DB::transaction(function (): void {
            $this->assertSame(0, Account::count());
            $system = $this->account(AccountType::System);
            $main = $this->account(AccountType::MainAgent, $system);
            $main->update(['name' => 'Synthetic native main agent']);
            $points = [$this->account(AccountType::Pos, $main), $this->account(AccountType::Pos, $main)];
            foreach ([[$main, 'scale-agent-1'], [$points[0], 'scale-point-1'], [$points[1], 'scale-point-2']] as [$account, $login]) {
                $this->userFor($account)->update(['login' => $login, 'email' => $login.'@example.invalid', 'name' => $login]);
            }
        });
        $this->assertSame(4, Account::count());
        $this->assertSame(3, User::count());
        fwrite(STDOUT, 'NATIVE_POINT_FIXTURE '.json_encode(['prepared' => true, 'database' => DB::connection()->getDatabaseName(), 'accounts' => 4, 'synthetic_owners' => 3, 'financial_rows_created' => 0], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function actors(): array
    {
        if (getenv('MASAL_POINT_WRITE_LOCK_NATIVE') !== '1') {
            $this->markTestSkipped('Requires explicitly isolated MariaDB synthetic point actors and exclusive QA execution.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        $this->assertSame('/home/dananiriq/masal-verification/finance-stock-20261006-01', realpath(base_path()));
        $this->assertSame(base_path('storage'), realpath(storage_path()));
        $this->prepareEmptyPointFixture();
        $actors = [];
        foreach (['scale-point-1', 'scale-point-2', 'scale-agent-1'] as $login) {
            $actor = User::where('login', $login)->firstOrFail();
            $this->assertStringEndsWith('@example.invalid', $actor->email);
            $this->assertSame('owner', $actor->membership->kind);
            $this->assertTrue($actor->isOperational());
            $actors[] = $actor;
        }
        [$first, $second, $main] = $actors;
        $this->assertSame(AccountType::Pos, $first->membership->account->type);
        $this->assertSame(AccountType::Pos, $second->membership->account->type);
        $this->assertSame(AccountType::MainAgent, $main->membership->account->type);
        $this->assertSame($main->membership->account_id, $first->membership->account->parent_id);
        $this->assertSame($main->membership->account_id, $second->membership->account->parent_id);
        $this->assertFalse(DB::table('digital_provider_attempts')->whereNotNull('active_order_id')->exists());
        $this->app->instance('request', Request::create('/api/v1/digital/orders', 'POST'));

        return $actors;
    }

    private function fingerprint(): string
    {
        $hash = hash_init('sha256');
        foreach (['finance_wallets', 'finance_transactions', 'finance_entries', 'finance_operations', 'finance_invoices', 'topup_grants', 'topup_wallet_bindings', 'topup_finance_events', 'digital_connections', 'digital_orders', 'digital_provider_attempts'] as $table) {
            hash_update($hash, $table);
            $key = $table === 'topup_wallet_bindings' ? 'wallet_id' : 'id';
            foreach (DB::table($table)->lazyById(500, $key) as $row) {
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
            }
        }

        return hash_final($hash);
    }

    private function worker(User $actor, string $mode = 'point', int $targetId = 0, string $requestId = ''): array
    {
        $root = '/home/dananiriq/masal-verification/audit84-20261014';
        $this->assertSame($root, realpath($root));
        $directory = $root.'/point-lock-'.Str::uuid();
        mkdir($directory, 0700);
        $process = new Process([PHP_BINARY, base_path('tests/Support/point-write-lock-worker.php'), $directory, $actor->login, $mode, (string) $targetId, $requestId], base_path(), ['MASAL_POINT_WRITE_LOCK_NATIVE' => '1']);
        $process->setTimeout(15);
        $process->start();

        return [$process, $directory];
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 8;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                $this->fail('Native point worker did not reach its expected barrier.');
            }
            usleep(10000);
        }
    }

    private function finish(Process $process, string $directory): array
    {
        touch($directory.'/go');
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode(file_get_contents($directory.'/result.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function cleanup(?Process $process, ?string $directory): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($process?->isRunning()) {
            touch($directory.'/go');
            $process->wait();
        }
        if ($directory && ! is_link($directory) && realpath(dirname($directory)) === '/home/dananiriq/masal-verification/audit84-20261014'
            && preg_match('/^point-lock-[a-f0-9-]{36}$/D', basename($directory))) {
            File::deleteDirectory($directory);
        }
    }

    public function test_different_points_can_enter_together_while_same_point_and_main_funding_stay_exclusive(): void
    {
        [$first, $second, $main] = $this->actors();
        $before = $this->fingerprint();
        foreach ([[$second, 'point', getenv('MASAL_POINT_LOCK_BASELINE') !== '1'], [$first, 'point', false], [$main, 'funding', false]] as [$actor, $mode, $parallel]) {
            $process = $directory = null;
            try {
                DB::beginTransaction();
                app(MutationGuard::class)->lock($first->fresh(), [], 'digital.create', true);
                app(DigitalAccess::class)->own($first->fresh(), 'digital.create', true);
                [$process, $directory] = $this->worker($actor, $mode, $first->membership->account_id);
                touch($directory.'/go');
                $this->waitFor($directory.'/trying');
                $deadline = microtime(true) + ($parallel ? 2 : 0.3);
                do {
                    usleep(10000);
                } while (! is_file($directory.'/acquired') && microtime(true) < $deadline);
                $this->assertSame($parallel, is_file($directory.'/acquired'), 'Different point admission should overlap; the same point and ancestor funding must wait.');
                DB::rollBack();
                $result = $this->finish($process, $directory);
                $this->assertTrue($result['ok'], json_encode($result));
                $this->assertSame(0, $result['status']);
            } finally {
                $this->cleanup($process, $directory);
            }
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_stock_point_admission_overlaps_but_same_point_funding_and_network_cap_remain_exclusive(): void
    {
        [$first, $second, $main] = $this->actors();
        $before = $this->fingerprint();
        foreach ([[true, $second, 'stock', true], [true, $first, 'stock', false], [true, $main, 'funding', false], [false, $second, 'stock', false]] as [$shared, $actor, $mode, $parallel]) {
            $process = $directory = null;
            try {
                DB::beginTransaction();
                app(MutationGuard::class)->lock($first->fresh(), [], 'sales.create', $shared);
                app(SalesAccess::class)->own($first->fresh(), 'sell.create', true, $shared);
                [$process, $directory] = $this->worker($actor, $mode, $first->membership->account_id);
                touch($directory.'/go');
                $this->waitFor($directory.'/trying');
                $deadline = microtime(true) + ($parallel ? 2 : 0.3);
                do {
                    usleep(10000);
                } while (! is_file($directory.'/acquired') && microtime(true) < $deadline);
                $this->assertSame($parallel, is_file($directory.'/acquired'), 'Stock point admission may overlap only without a shared network cap, point conflict or ancestor funding.');
                DB::rollBack();
                $result = $this->finish($process, $directory);
                $this->assertTrue($result['ok'], json_encode($result));
            } finally {
                $this->cleanup($process, $directory);
            }
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_first_login_device_claim_is_serialized_and_second_device_cannot_overwrite_it(): void
    {
        [$point] = $this->actors();
        $before = $this->fingerprint();
        $accountId = $point->membership->account_id;
        $previous = DB::table('pos_login_bindings')->where('account_id', $accountId)->first();
        $process = $directory = null;
        $devices = [];
        try {
            foreach (['first', 'second'] as $name) {
                $identity = $this->posLoginIdentity();
                $id = DB::table('pos_registered_devices')->insertGetId([
                    'account_id' => $accountId, 'identifier_hash' => hash('sha256', 'native-login-binding-'.$name.'-'.Str::uuid()),
                    'kind' => 'browser', 'model' => 'Synthetic native login '.$name, 'app_version' => '1.4.0',
                    'status' => 'pending', 'version' => 1, 'proof_public_key' => $identity['proof_public_key'], 'proof_key_kind' => 'webcrypto',
                    'proof_enrolled_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $devices[] = DB::table('pos_registered_devices')->where('id', $id)->first();
            }
            DB::table('pos_login_bindings')->updateOrInsert(['account_id' => $accountId], [
                'registered_device_id' => null, 'version' => 1, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $request = Request::create('/api/v1/auth/login', 'POST');
            $request->setLaravelSession(app('session')->driver());
            DB::beginTransaction();
            Account::whereKey($accountId)->lockForUpdate()->firstOrFail();
            $this->assertTrue(app(PosLoginDevice::class)->acceptHeartbeat($accountId, $devices[0], hash('sha256', $devices[0]->proof_public_key), $request));
            [$process, $directory] = $this->worker($point, 'login-binding', $devices[1]->id);
            touch($directory.'/go');
            $this->waitFor($directory.'/trying');
            usleep(300000);
            $this->assertFileDoesNotExist($directory.'/acquired', 'Second enrollment must wait on the same POS account lock.');
            DB::commit();
            $result = $this->finish($process, $directory);
            $this->assertTrue($result['ok'], json_encode($result));
            $this->assertFalse($result['login_allowed'], 'A current locking read must retain the first committed device after waiting.');
            $this->assertSame($devices[0]->id, DB::table('pos_login_bindings')->where('account_id', $accountId)->value('registered_device_id'));
            fwrite(STDOUT, 'NATIVE_LOGIN_BINDING '.json_encode(['same_point_serialized' => true, 'second_device_denied' => true, 'financial_rows_changed' => false], JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            $this->cleanup($process, $directory);
            if ($previous) {
                DB::table('pos_login_bindings')->where('account_id', $accountId)->update((array) $previous);
            } else {
                DB::table('pos_login_bindings')->where('account_id', $accountId)->delete();
            }
            DB::table('pos_registered_devices')->whereIn('id', array_map(fn ($device): int => $device->id, $devices))->delete();
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_committed_ancestor_pause_is_seen_after_waiting_despite_an_existing_repeatable_read_snapshot(): void
    {
        [$point, , $main] = $this->actors();
        $before = $this->fingerprint();
        $process = $directory = null;
        $account = $main->membership->account;
        try {
            DB::beginTransaction();
            app(MutationGuard::class)->lock($main, [$point->membership->account_id]);
            $account->update(['status' => 'disabled']);
            [$process, $directory] = $this->worker($point);
            touch($directory.'/go');
            $this->waitFor($directory.'/trying');
            usleep(250000);
            $this->assertFileDoesNotExist($directory.'/acquired');
            DB::commit();
            $result = $this->finish($process, $directory);
            $this->assertFalse($result['ok']);
            $this->assertSame(403, $result['status']);
        } finally {
            $this->cleanup($process, $directory);
            $account->update(['status' => 'active']);
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_committed_inherited_stop_is_seen_after_waiting_despite_an_existing_repeatable_read_snapshot(): void
    {
        [$point, , $main] = $this->actors();
        $before = $this->fingerprint();
        $process = $directory = $stop = null;
        try {
            DB::beginTransaction();
            app(MutationGuard::class)->lock($main, [$point->membership->account_id]);
            $stop = DirectStop::create(['account_id' => $main->membership->account_id, 'stops' => ['sales' => true], 'version' => 1]);
            [$process, $directory] = $this->worker($point);
            touch($directory.'/go');
            $this->waitFor($directory.'/trying');
            usleep(250000);
            DB::commit();
            $result = $this->finish($process, $directory);
            $this->assertFalse($result['ok']);
            $this->assertSame(423, $result['status']);
        } finally {
            $this->cleanup($process, $directory);
            $stop?->delete();
        }
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_revoking_point_permission_while_it_waits_invalidates_its_previously_read_session(): void
    {
        [$point, , $main] = $this->actors();
        $before = $this->fingerprint();
        $process = $directory = null;
        $membershipId = $point->membership->id;
        $permissionId = DB::table('permissions')->where('name', 'digital.create')->value('id');
        $previous = DB::table('membership_permissions')->where('membership_id', $membershipId)->where('permission_id', $permissionId)->first();
        try {
            DB::beginTransaction();
            app(MutationGuard::class)->lock($main, [$point->membership->account_id]);
            DB::table('membership_permissions')->updateOrInsert(['membership_id' => $membershipId, 'permission_id' => $permissionId], ['allowed' => false]);
            DB::table('users')->where('id', $point->id)->increment('session_version');
            [$process, $directory] = $this->worker($point);
            touch($directory.'/go');
            $this->waitFor($directory.'/trying');
            usleep(250000);
            DB::commit();
            $result = $this->finish($process, $directory);
            $this->assertFalse($result['ok']);
            $this->assertSame(401, $result['status']);
        } finally {
            $this->cleanup($process, $directory);
            if ($previous) {
                DB::table('membership_permissions')->where('membership_id', $membershipId)->where('permission_id', $permissionId)->update(['allowed' => $previous->allowed]);
            } else {
                DB::table('membership_permissions')->where('membership_id', $membershipId)->where('permission_id', $permissionId)->delete();
            }
            DB::table('users')->where('id', $point->id)->increment('session_version');
        }

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_request_gap_then_connection_reproduces_a_deadlock_and_connection_first_removes_that_cycle(): void
    {
        [$first, $second] = $this->actors();
        $before = $this->fingerprint();
        $connectionId = DigitalOrder::where('account_id', $second->membership->account_id)->where('status', 'succeeded')->value('connection_id');
        $this->assertNotNull($connectionId);
        $observed = [];
        foreach ([false, true] as $connectionFirst) {
            $prefix = 'zz-native-gap-'.Str::uuid();
            $this->assertFalse(DigitalOrder::where('request_id', '>=', $prefix)->exists());
            $process = $directory = null;
            $driverError = 0;
            try {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::beginTransaction();
                app(MutationGuard::class)->lock($first->fresh(), [], 'digital.create', true);
                if ($connectionFirst) {
                    DigitalConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
                }
                $this->assertNull(DigitalOrder::where('request_id', $prefix.'-a')->lockForUpdate()->first());
                [$process, $directory] = $this->worker($second, 'insert', $connectionId, $prefix.'-b');
                touch($directory.'/go');
                $this->waitFor($directory.'/trying');
                if ($connectionFirst) {
                    usleep(250000);
                    $this->assertFileDoesNotExist($directory.'/connection');
                    touch($directory.'/go-insert');
                } else {
                    $this->waitFor($directory.'/connection');
                    touch($directory.'/go-insert');
                    $this->waitFor($directory.'/inserting');
                    usleep(100000);
                    try {
                        DigitalConnection::whereKey($connectionId)->lockForUpdate()->firstOrFail();
                    } catch (QueryException $failure) {
                        $driverError = (int) ($failure->errorInfo[1] ?? 0);
                    }
                }
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $result = $this->finish($process, $directory);
                if ($connectionFirst) {
                    $this->assertTrue($result['ok'], json_encode($result));
                    $this->assertTrue($result['inserted_before_rollback']);
                    $this->assertArrayNotHasKey('driver_error', $result);
                } else {
                    $this->assertContains(1213, [$driverError, $result['driver_error'] ?? 0], 'The request gap / connection inversion must reproduce the native deadlock.');
                }
                $observed[] = ['connection_first' => $connectionFirst, 'parent_driver_error' => $driverError, 'worker_driver_error' => $result['driver_error'] ?? 0];
            } finally {
                if ($directory) {
                    touch($directory.'/go-insert');
                }
                $this->cleanup($process, $directory);
            }
        }
        $this->assertSame($before, $this->fingerprint());
        fwrite(STDOUT, 'REQUEST_CONNECTION_LOCK_ORDER '.json_encode(['two_real_mysql_sessions' => true, 'rollback_only' => true, 'results' => $observed]).PHP_EOL);
    }
}
