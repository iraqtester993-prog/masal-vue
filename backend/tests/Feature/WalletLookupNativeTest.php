<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Finance\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WalletLookupNativeTest extends TestCase
{
    private function guard(): void
    {
        if (getenv('MASAL_WALLET_LOOKUP_NATIVE') !== '1') {
            $this->markTestSkipped('Requires explicit execution against the retained disposable MySQL fixture.');
        }
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame('dananiriq_masalverify', DB::connection()->getDatabaseName());
        $this->assertSame('/home/dananiriq/masal-verification/finance-stock-20261006-01', realpath(base_path()));
        $this->assertSame(base_path('storage'), realpath(storage_path()));
        $this->assertFalse((bool) DB::table('runtime_write_gate')->where('id', 1)->value('blocked'));
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertGreaterThanOrEqual(3000000, DB::table('digital_orders')->count());
        $this->assertGreaterThanOrEqual(1000000, DB::table('finance_entries')->count());
    }

    public function test_existing_shared_wallet_lookup_does_not_upgrade_duplicate_insert_shared_locks(): void
    {
        $this->guard();
        $system = Account::where('type', AccountType::System)->firstOrFail();
        $wallet = Wallet::where('account_id', $system->id)->where('service', 'voucher')->where('currency', 'IQD')->where('kind', 'external')->firstOrFail();
        $before = $this->facts();
        $rowBefore = DB::table('finance_wallets')->where('id', $wallet->id)->first();
        $results = $this->workers($system->id, 'voucher', 'external', 'existing');
        $errors = array_values(array_filter($results, fn ($result) => ! $result['ok']));
        if (getenv('MASAL_WALLET_LOOKUP_BEFORE') === '1') {
            $this->assertCount(1, $errors, json_encode($results));
            $this->assertSame(1213, $errors[0]['driver_error']);
            $this->assertSame(2, array_sum(array_column($results, 'duplicate_inserts')));
        } else {
            $this->assertSame([], $errors, json_encode($results));
            $this->assertSame([$wallet->id, $wallet->id], array_column($results, 'wallet_id'));
            $this->assertSame(0, array_sum(array_column($results, 'duplicate_inserts')));
        }
        $this->assertEquals($rowBefore, DB::table('finance_wallets')->where('id', $wallet->id)->first());
        $this->assertSame($before, $this->facts());
        fwrite(STDOUT, 'EXISTING_WALLET_NATIVE '.json_encode(['before' => getenv('MASAL_WALLET_LOOKUP_BEFORE') === '1', 'processes' => 2,
            'ok' => count($results) - count($errors), 'deadlocks' => count($errors), 'duplicate_inserts' => array_sum(array_column($results, 'duplicate_inserts')),
            'financial_facts_unchanged' => true, 'provider_calls' => 0]).PHP_EOL);
    }

    public function test_concurrent_first_wallet_creation_retries_as_one_empty_unique_wallet(): void
    {
        $this->guard();
        $point = Account::where('type', AccountType::Pos)->where('name', 'like', 'Scale point %')->whereNotIn('id', DB::table('finance_wallets')->where('service', 'cash')->where('currency', 'IQD')->where('kind', 'account')->select('account_id'))->orderByDesc('id')->firstOrFail();
        $before = $this->facts();
        $created = null;
        try {
            $results = $this->workers($point->id, 'cash', 'account', 'missing');
            $this->assertSame([true, true], array_column($results, 'ok'), json_encode($results));
            $this->assertCount(1, array_unique(array_column($results, 'wallet_id')));
            $this->assertLessThanOrEqual(5, max(array_column($results, 'attempts')));
            $wallets = Wallet::where('account_id', $point->id)->where('service', 'cash')->where('currency', 'IQD')->where('kind', 'account')->get();
            $this->assertCount(1, $wallets);
            $created = $wallets->first();
            $this->assertSame(0, $created->balance_minor);
            $this->assertSame(0, $created->held_minor);
            $this->assertSame(0, DB::table('finance_entries')->where('wallet_id', $created->id)->count());
            $this->assertSame($before['entries'], DB::table('finance_entries')->count());
            $this->assertSame($before['transactions'], DB::table('finance_transactions')->count());
            fwrite(STDOUT, 'MISSING_WALLET_NATIVE '.json_encode(['processes' => 2, 'unique_wallets' => 1, 'attempts' => array_column($results, 'attempts'),
                'zero_balance' => true, 'entries_added' => 0, 'provider_calls' => 0]).PHP_EOL);
        } finally {
            $created ??= Wallet::where('account_id', $point->id)->where('service', 'cash')->where('currency', 'IQD')->where('kind', 'account')->first();
            if ($created && $created->balance_minor === 0 && $created->held_minor === 0 && ! DB::table('finance_entries')->where('wallet_id', $created->id)->exists()) {
                DB::table('finance_wallets')->where('id', $created->id)->where('account_id', $point->id)->where('service', 'cash')->where('currency', 'IQD')->where('kind', 'account')->where('balance_minor', 0)->where('held_minor', 0)->delete();
            }
        }
        $this->assertSame($before, $this->facts());
    }

    private function facts(): array
    {
        return ['wallets' => DB::table('finance_wallets')->count(), 'entries' => DB::table('finance_entries')->count(), 'transactions' => DB::table('finance_transactions')->count(),
            'balances' => (int) DB::table('finance_wallets')->sum('balance_minor'), 'held' => (int) DB::table('finance_wallets')->sum('held_minor')];
    }

    private function workers(int $accountId, string $service, string $kind, string $mode): array
    {
        $root = '/home/dananiriq/masal-verification/audit-rollup86-20261014';
        $this->assertSame($root, realpath($root));
        $directory = $root.'/wallet-native-'.Str::uuid();
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach ([0, 1] as $slot) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/ledger-wallet-lookup-worker.php'), $directory, (string) $slot, (string) $accountId, $service, $kind, $mode], base_path(), ['MASAL_WALLET_LOOKUP_NATIVE' => '1']);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                if (microtime(true) >= $deadline) {
                    $this->fail('Native wallet workers did not reach the isolated start barrier.');
                }
                usleep(10000);
            }
            touch($directory.'/go');
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            return array_map(fn ($slot) => json_decode(file_get_contents($directory.'/result-'.$slot.'.json'), true, 32, JSON_THROW_ON_ERROR), [0, 1]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if (realpath(dirname($directory)) === $root && ! is_link($directory) && str_starts_with(basename($directory), 'wallet-native-')) {
                File::deleteDirectory($directory);
            }
        }
    }
}
