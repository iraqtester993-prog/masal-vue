<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Backups\CpanelRestorePlatform;
use App\Services\Backups\SnapshotArchive;
use App\Services\Backups\SnapshotImporter;
use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CpanelRestoreNativeTest extends TestCase
{
    use CreatesAccounts;

    private const ROOT = '/home/dananiriq/masal-verification/restore-platform-20261006-07';

    private function guard(): void
    {
        if (env('MASAL_NATIVE_CPANEL_SHADOW') !== 'restore-platform-20261006-07') {
            $this->markTestSkipped('Actual cPanel prepare and environment switch require the explicitly enabled private Linux shadow.');
        }
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! app()->environment('testing')
            || realpath(base_path()) !== self::ROOT.'/releases/verify' || realpath(storage_path()) !== self::ROOT.'/shared/storage'
            || realpath(base_path('.env')) !== self::ROOT.'/shared/.env' || config('backups.runtime_root') !== self::ROOT
            || config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify'
            || DB::connection()->selectOne('SELECT DATABASE() AS name')->name !== 'dananiriq_masalverify'
            || config('database.connections.mysql.username') !== 'dananiriq_masalapp') {
            throw new RuntimeException('Native restore acceptance is restricted to the private shadow and disposable verification source.');
        }
        app()->useEnvironmentPath(base_path());
        app()->loadEnvironmentFrom('.env');
        $parsed = Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'));
        if (($parsed['APP_ENV'] ?? null) !== 'testing' || ($parsed['DB_DATABASE'] ?? null) !== 'dananiriq_masalverify'
            || ! hash_equals((string) config('app.key'), $parsed['APP_KEY'] ?? '')
            || ($parsed['BACKUP_RUNTIME_ROOT'] ?? null) !== self::ROOT || is_file(storage_path('framework/down'))) {
            throw new RuntimeException('Private shadow environment does not match its isolated verification configuration.');
        }
    }

    /** @return array{identifier: string, connection: array} */
    private function prepare(CpanelRestorePlatform $platform, string $job, string $snapshot, Connection $source): array
    {
        $scratch = $platform->createScratch($job);
        $this->assertMatchesRegularExpression('/^dananiriq_mr_[a-f0-9]{12}$/D', $scratch['identifier']);
        $this->assertNotSame($source->getDatabaseName(), $scratch['identifier']);
        $alias = 'cpanel_native_scratch';
        config(['database.connections.'.$alias => $scratch['connection']]);
        try {
            $this->assertSame([], DB::connection($alias)->getSchemaBuilder()->getTables($scratch['identifier']));
            app(SnapshotImporter::class)->restore($snapshot, $alias, $source, 'backups/native-restore/'.$job.'/assets');
            $this->assertSame($source->table('users')->count(), DB::connection($alias)->table('users')->count());
            $this->assertSame(0, DB::connection($alias)->table('sessions')->count());
        } finally {
            DB::purge($alias);
            config(['database.connections.'.$alias => null]);
        }

        return $scratch;
    }

    public function test_real_cpanel_prepare_fresh_cached_boot_success_then_second_job_rollback_preserves_private_source(): void
    {
        $this->guard();
        $owner = User::where('status', 'active')->whereHas('membership', fn ($query) => $query->where('kind', 'owner')->where('status', 'active')->whereHas('account', fn ($accounts) => $accounts->where('type', 'system')->where('status', 'active')->whereNull('archived_at')))->first();
        if (! $owner) {
            $owner = $this->userFor($this->account(AccountType::System));
        }
        $this->assertTrue($owner->isOperational());
        config(['database.connections.cpanel_native_source' => config('database.connections.mysql')]);
        $source = DB::connection('cpanel_native_source');
        $sourceCounts = ['users' => $source->table('users')->count(), 'transactions' => $source->table('finance_transactions')->count(), 'entries' => $source->table('finance_entries')->count()];
        $sourceGateVersion = (int) $source->table('runtime_write_gate')->value('version');
        $initialEnvironment = file_get_contents(self::ROOT.'/shared/.env');
        $initialKeyHash = hash('sha256', (string) config('app.key'));
        $job = (string) Str::uuid();
        $snapshot = 'backups/native-restore/'.$job.'/snapshot.json';
        app(SnapshotArchive::class)->create($source, $snapshot);
        $platform = new CpanelRestorePlatform;
        $this->assertSame(['prepare' => true, 'switch' => true, 'reason' => null], $platform->capabilities());
        $scratch = $this->prepare($platform, $job, $snapshot, $source);

        $maintenance = (new CpanelRestorePlatform)->beginMaintenance($job);
        $this->assertTrue((bool) $source->table('runtime_write_gate')->value('blocked'));
        $this->assertSame($sourceGateVersion + 1, (int) $source->table('runtime_write_gate')->value('version'));
        $this->assertFileExists(storage_path('framework/down'));
        $switched = (new CpanelRestorePlatform)->switchVerified($job, $scratch);
        $this->assertSame($maintenance, $switched);
        $this->assertSame($scratch['identifier'], DB::connection()->selectOne('SELECT DATABASE() AS name')->name);
        (new CpanelRestorePlatform)->finishMaintenance($job);

        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(3, (int) DB::table('runtime_write_gate')->value('version'));
        $this->assertSame($sourceCounts, ['users' => $source->table('users')->count(), 'transactions' => $source->table('finance_transactions')->count(), 'entries' => $source->table('finance_entries')->count()]);
        $this->assertSame($initialKeyHash, hash('sha256', Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'))['APP_KEY']));
        $this->assertSame(str_replace('DB_DATABASE=dananiriq_masalverify', 'DB_DATABASE='.$scratch['identifier'], $initialEnvironment), file_get_contents(self::ROOT.'/shared/.env'));

        $rollbackSourceAlias = 'cpanel_native_rollback_source';
        config(['database.connections.'.$rollbackSourceAlias => config('database.connections.mysql')]);
        $rollbackSource = DB::connection($rollbackSourceAlias);
        $secondJob = (string) Str::uuid();
        $secondSnapshot = 'backups/native-restore/'.$secondJob.'/snapshot.json';
        app(SnapshotArchive::class)->create($rollbackSource, $secondSnapshot);
        $secondScratch = $this->prepare($platform, $secondJob, $secondSnapshot, $rollbackSource);
        $beforeRollback = file_get_contents(self::ROOT.'/shared/.env');
        $secondMaintenance = (new CpanelRestorePlatform)->beginMaintenance($secondJob);
        (new CpanelRestorePlatform)->switchVerified($secondJob, $secondScratch);
        $this->assertSame($secondScratch['identifier'], DB::connection()->selectOne('SELECT DATABASE() AS name')->name);
        (new CpanelRestorePlatform)->rollbackSwitch($secondMaintenance['rollback']);

        $this->assertSame($beforeRollback, file_get_contents(self::ROOT.'/shared/.env'));
        $this->assertSame($scratch['identifier'], DB::connection()->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame($initialKeyHash, hash('sha256', (string) config('app.key')));
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertSame('', file_get_contents(self::ROOT.'/.local/restore/active.lock'));
        $this->assertSame($sourceCounts, ['users' => $source->table('users')->count(), 'transactions' => $source->table('finance_transactions')->count(), 'entries' => $source->table('finance_entries')->count()]);

        $this->assertSame(1, $source->table('runtime_write_gate')->where('id', 1)->where('restore_job_id', $job)->where('blocked', true)
            ->update(['blocked' => false, 'restore_job_id' => null, 'version' => $sourceGateVersion + 2, 'updated_at' => now()]));
        DB::purge('cpanel_native_source');
        DB::purge($rollbackSourceAlias);
        config(['database.connections.cpanel_native_source' => null, 'database.connections.'.$rollbackSourceAlias => null]);
        $this->assertTrue(Storage::disk('local')->exists($snapshot));
        $this->assertTrue(Storage::disk('local')->exists($secondSnapshot));
    }
}
