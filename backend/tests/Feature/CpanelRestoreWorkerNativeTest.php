<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Backups\RestoreJob;
use App\Models\Backups\ServerBackup;
use App\Services\Backups\BackupWorkflow;
use App\Services\Backups\CpanelRestorePlatform;
use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class CpanelRestoreWorkerNativeTest extends TestCase
{
    use CreatesAccounts;

    private const ROOT = '/home/dananiriq/masal-verification/restore-platform-20261006-07';

    private function guard(): void
    {
        if (env('MASAL_NATIVE_CPANEL_SHADOW') !== 'restore-platform-20261006-07') {
            $this->markTestSkipped('The real restore worker requires the explicitly enabled private Linux cPanel shadow.');
        }
        if (PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || ! app()->environment('testing')
            || realpath(base_path()) !== self::ROOT.'/releases/verify' || realpath(storage_path()) !== self::ROOT.'/shared/storage'
            || realpath(base_path('.env')) !== self::ROOT.'/shared/.env' || config('backups.runtime_root') !== self::ROOT
            || config('database.default') !== 'mysql' || DB::connection()->getDatabaseName() !== 'dananiriq_masalverify'
            || DB::connection()->selectOne('SELECT DATABASE() AS name')->name !== 'dananiriq_masalverify'
            || config('database.connections.mysql.username') !== 'dananiriq_masalapp') {
            throw new RuntimeException('Worker acceptance is restricted to the private shadow and disposable verification source.');
        }
        app()->useEnvironmentPath(base_path());
        app()->loadEnvironmentFrom('.env');
        $parsed = Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'));
        if (($parsed['APP_ENV'] ?? null) !== 'testing' || ($parsed['DB_DATABASE'] ?? null) !== 'dananiriq_masalverify'
            || ! hash_equals((string) config('app.key'), $parsed['APP_KEY'] ?? '')
            || ($parsed['BACKUP_RUNTIME_ROOT'] ?? null) !== self::ROOT || is_file(storage_path('framework/down'))
            || ! empty(config('database.connections.mysql.url')) || DB::transactionLevel() !== 0) {
            throw new RuntimeException('Private shadow environment is not ready for a committed worker acceptance.');
        }
        if (Account::where('type', '<>', AccountType::System->value)->exists()
            || RestoreJob::whereIn('status', ['queued', 'preparing', 'validated', 'switching', 'rollback_failed'])->exists()) {
            throw new RuntimeException('Disposable source must contain at most its system account and no unresolved restore job.');
        }
        $gate = DB::table('runtime_write_gate')->where('id', 1)->first();
        if (! $gate || $gate->blocked || $gate->restore_job_id !== null) {
            throw new RuntimeException('Disposable source write gate must be released before worker acceptance.');
        }
    }

    private function digest(Connection $db, string $table): string
    {
        $rows = $db->table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function freshHealth(string $database, string $jobId): void
    {
        $nonce = bin2hex(random_bytes(16));
        $script = <<<'PHP'
try {
    require $argv[1].'/vendor/autoload.php';
    $app = require $argv[1].'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = \Illuminate\Support\Facades\DB::connection('mysql');
    $gate = $db->table('runtime_write_gate')->where('id', 1)->first();
    if (! $app->environment('testing') || ! $app->configurationIsCached() || $app->isDownForMaintenance()
        || realpath(base_path()) !== $argv[1] || $db->selectOne('SELECT DATABASE() AS name')->name !== $argv[2]
        || ! $gate || $gate->blocked || $gate->restore_job_id !== null
        || $db->table('restore_jobs')->where('id', $argv[3])->value('status') !== 'completed') { exit(2); }
    echo json_encode(['ready' => true, 'nonce' => $argv[4]], JSON_THROW_ON_ERROR);
} catch (\Throwable) { exit(3); }
PHP;
        $values = Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'));
        $keys = array_merge(array_keys($values), ['DB_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'APP_ENV', 'APP_KEY', 'APP_BASE_PATH', 'APP_CONFIG_CACHE', 'LARAVEL_ENV']);
        $result = Process::path(base_path())->env(array_fill_keys(array_unique($keys), false))->timeout(60)
            ->run([config('backups.php_binary'), '-r', $script, base_path(), $database, $jobId, $nonce]);
        $this->assertTrue($result->successful(), 'Restored worker target must boot successfully in a fresh PHP process.');
        $payload = json_decode($result->output(), true);
        $this->assertSame(true, $payload['ready'] ?? null);
        $this->assertSame($nonce, $payload['nonce'] ?? null);
    }

    public function test_real_worker_copies_control_records_and_completes_both_databases_without_losing_current_source(): void
    {
        $this->guard();
        config(['backups.platform_class' => CpanelRestorePlatform::class]);
        $system = Account::where('type', AccountType::System->value)->first() ?? $this->account(AccountType::System);
        $actor = $this->userFor($system);
        $testPassword = 'private-shadow-worker-acceptance-only';
        $actor->update(['password' => $testPassword]);
        $actor->refresh();
        $main = $this->account(AccountType::MainAgent, $system);
        $sourceAlias = 'cpanel_native_worker_source';
        config(['database.connections.'.$sourceAlias => ['name' => $sourceAlias] + config('database.connections.mysql')]);
        $source = DB::connection($sourceAlias);
        $this->assertSame($sourceAlias, $source->getName());
        $this->assertSame('dananiriq_masalverify', $source->selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(2, $source->table('accounts')->count());
        $sourceGateVersion = (int) $source->table('runtime_write_gate')->where('id', 1)->value('version');
        $sourceSessionVersion = (int) $actor->session_version;
        $sessionId = 'native-worker-'.Str::uuid();
        $source->table('sessions')->insert(['id' => $sessionId, 'user_id' => $actor->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Private shadow worker acceptance', 'payload' => base64_encode('verification-only'), 'last_activity' => time()]);
        $source->table('user_presences')->insert(['user_id' => $actor->id, 'session_hash' => hash('sha256', $sessionId), 'session_version' => $sourceSessionVersion, 'connected' => true, 'sharing' => true, 'consent_version' => 7, 'latitude' => 33.3152, 'longitude' => 44.3661, 'accuracy' => 15, 'location_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $initialEnvironment = Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'));
        $initialEnvironmentHash = hash('sha256', json_encode(array_diff_key($initialEnvironment, ['DB_DATABASE' => true]), JSON_THROW_ON_ERROR));
        $initialKeyHash = hash('sha256', (string) config('app.key'));
        $workflow = app(BackupWorkflow::class);
        $this->assertSame(['prepare' => true, 'switch' => true, 'reason' => null], $workflow->platform()->capabilities());
        $backup = $workflow->create($actor);
        $preview = $workflow->preview($actor, null, $backup->id)->refresh();
        $this->assertSame(1, $preview->version);
        $this->assertTrue($preview->reference_verified);
        $this->assertSame(2, $backup->manifest['counts']['accounts']);
        $later = $this->account(AccountType::Pos, $main);
        $job = $workflow->queue($actor, ['preview_id' => $preview->id, 'version' => $preview->version, 'current_password' => $testPassword]);
        $this->assertSame('queued', $job->status);
        $sourceDigests = [];
        foreach (['accounts', 'account_memberships', 'users', 'finance_wallets', 'finance_transactions', 'finance_entries', 'finance_invoices', 'user_presences'] as $table) {
            $sourceDigests[$table] = $this->digest($source, $table);
        }
        $controlCounts = [];
        foreach (['server_backups', 'backup_previews', 'restore_jobs'] as $table) {
            $controlCounts[$table] = $source->table($table)->count();
        }

        $this->artisan('backups:work')->expectsOutput('Restore job '.$job->id.': completed.')->assertSuccessful();

        $target = DB::connection();
        $targetDatabase = $target->selectOne('SELECT DATABASE() AS name')->name;
        $this->assertMatchesRegularExpression('/^dananiriq_mr_[a-f0-9]{12}$/D', $targetDatabase);
        $this->assertNotSame('dananiriq_masalverify', $targetDatabase);
        $sourceJob = RestoreJob::on($sourceAlias)->findOrFail($job->id);
        $this->assertSame('completed', $sourceJob->status);
        $this->assertNotNull($sourceJob->completed_at);
        $this->assertNotNull($sourceJob->safety_backup_id);
        $targetJob = RestoreJob::findOrFail($job->id);
        $this->assertSame('completed', $targetJob->status);
        $this->assertSame($sourceJob->version, $targetJob->version);
        $this->assertSame($sourceJob->safety_backup_id, $targetJob->safety_backup_id);
        foreach (['scratch', 'rollback'] as $field) {
            $sourceValue = json_encode($sourceJob->{$field}, JSON_THROW_ON_ERROR);
            $targetValue = json_encode($targetJob->{$field}, JSON_THROW_ON_ERROR);
            $this->assertTrue(hash_equals(hash('sha256', $sourceValue), hash('sha256', $targetValue)), 'Encrypted '.$field.' must decrypt to the same control data in both databases.');
        }
        $this->assertArrayNotHasKey('scratch', $targetJob->toArray());
        $this->assertArrayNotHasKey('rollback', $targetJob->toArray());
        $safety = ServerBackup::on($sourceAlias)->findOrFail($sourceJob->safety_backup_id);
        $this->assertSame('completed', $safety->status);
        $this->assertSame(3, $safety->manifest['counts']['accounts']);
        $this->assertSame(2, ServerBackup::findOrFail($backup->id)->manifest['counts']['accounts']);
        $this->assertSame(3, ServerBackup::findOrFail($safety->id)->manifest['counts']['accounts']);
        foreach ($controlCounts as $table => $count) {
            $this->assertSame($count + ($table === 'server_backups' ? 1 : 0), $source->table($table)->count(), 'Source control records must remain in the original database.');
            $this->assertSame($source->table($table)->count(), $target->table($table)->count(), 'Source control records must be copied to the restored target.');
        }
        $this->assertSame(3, $source->table('accounts')->count());
        $this->assertSame(2, $target->table('accounts')->count());
        $this->assertTrue($source->table('accounts')->where('id', $later->id)->exists());
        $this->assertFalse($target->table('accounts')->where('id', $later->id)->exists());
        foreach ($sourceDigests as $table => $digest) {
            $this->assertSame($digest, $this->digest($source, $table), 'Current source business data must remain unchanged: '.$table);
        }
        $this->assertTrue($source->table('sessions')->where('id', $sessionId)->exists());
        $this->assertSame(0, $target->table('sessions')->count());
        $restoredUser = $target->table('users')->where('id', $actor->id)->first();
        $this->assertSame($sourceSessionVersion + 1, (int) $restoredUser->session_version);
        $this->assertNull($restoredUser->remember_token);
        $presence = $target->table('user_presences')->where('user_id', $actor->id)->first();
        $this->assertFalse((bool) $presence->connected);
        $this->assertFalse((bool) $presence->sharing);
        $this->assertSame(8, (int) $presence->consent_version);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        $this->assertSame('', file_get_contents(self::ROOT.'/.local/restore/active.lock'));
        $this->assertFalse((bool) $target->table('runtime_write_gate')->where('id', 1)->value('blocked'));
        $this->assertNull($target->table('runtime_write_gate')->where('id', 1)->value('restore_job_id'));
        $this->assertSame(3, (int) $target->table('runtime_write_gate')->where('id', 1)->value('version'));
        $this->assertTrue((bool) $source->table('runtime_write_gate')->where('id', 1)->value('blocked'));
        $this->assertSame($job->id, $source->table('runtime_write_gate')->where('id', 1)->value('restore_job_id'));
        $this->assertSame($sourceGateVersion + 1, (int) $source->table('runtime_write_gate')->where('id', 1)->value('version'));
        $updatedEnvironment = Dotenv::parse(file_get_contents(self::ROOT.'/shared/.env'));
        $this->assertSame($targetDatabase, $updatedEnvironment['DB_DATABASE']);
        $this->assertSame($initialEnvironmentHash, hash('sha256', json_encode(array_diff_key($updatedEnvironment, ['DB_DATABASE' => true]), JSON_THROW_ON_ERROR)));
        $this->assertSame($initialKeyHash, hash('sha256', $updatedEnvironment['APP_KEY']));
        $this->assertTrue(Storage::disk('local')->exists($backup->path));
        $this->assertTrue(Storage::disk('local')->exists($safety->path));
        $this->freshHealth($targetDatabase, $job->id);

        $this->assertSame(1, $source->table('runtime_write_gate')->where('id', 1)->where('restore_job_id', $job->id)
            ->where('version', $sourceGateVersion + 1)->where('blocked', true)
            ->update(['blocked' => false, 'restore_job_id' => null, 'version' => $sourceGateVersion + 2, 'updated_at' => now()]));
        DB::purge($sourceAlias);
        config(['database.connections.'.$sourceAlias => null]);
    }
}
