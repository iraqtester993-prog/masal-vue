<?php

namespace Tests\Feature;

use App\Services\Backups\CpanelRestorePlatform;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use RuntimeException;
use Tests\TestCase;

class CpanelRestorePlatformTest extends TestCase
{
    private const JOB = 'e50a5c88-4e70-4a66-a51c-5d91db3cdb1c';

    private const OTHER_JOB = '947a327a-4e69-456b-b4aa-147f406f1b87';

    private const SOURCE = 'dananiriq_testsource';

    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            if (str_starts_with(realpath($folder) ?: '', sys_get_temp_dir().DIRECTORY_SEPARATOR.'masal-cpanel-test-')) {
                File::deleteDirectory($folder);
            }
        }
        parent::tearDown();
    }

    /** @return array{platform: CpanelRestorePlatform, paths: array, content: string, databases: \ArrayObject} */
    private function fixture(?PDO $sourcePdo = null): array
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'masal-cpanel-test-'.bin2hex(random_bytes(8));
        $this->folders[] = $folder;
        mkdir($folder, 0700);
        mkdir($folder.'/restore', 0700);
        mkdir($folder.'/framework', 0700);
        $paths = ['environment' => $folder.'/.env', 'folder' => $folder.'/restore', 'maintenance' => $folder.'/framework/down'];
        $content = "APP_ENV=testing\nAPP_KEY=PRIVATE_FIXTURE_KEY\nDB_CONNECTION=mysql\nDB_DATABASE=".self::SOURCE."\nDB_PASSWORD=PRIVATE_FIXTURE_PASSWORD\nOTHER_VALUE=keep_me\n";
        file_put_contents($paths['environment'], $content);
        $connection = ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'database' => self::SOURCE, 'username' => 'dananiriq_testuser', 'password' => 'PRIVATE_FIXTURE_PASSWORD', 'url' => null];
        config(['database.default' => 'mysql', 'database.connections.mysql' => $connection, 'app.maintenance.driver' => 'file', 'backups.cpanel_user' => 'dananiriq', 'backups.php_binary' => PHP_BINARY, 'backups.uapi' => PHP_BINARY]);
        DB::purge('mysql');
        $databases = new \ArrayObject;
        DB::extend('mysql', function (array $config, string $name) use ($databases, $sourcePdo): SQLiteConnection {
            $database = $config['database'];
            if (! isset($databases[$database])) {
                $pdo = $database === self::SOURCE && $sourcePdo !== null ? $sourcePdo : new PDO('sqlite::memory:');
                $pdo->exec('CREATE TABLE runtime_write_gate (id INTEGER PRIMARY KEY, blocked INTEGER NOT NULL, restore_job_id TEXT, version INTEGER NOT NULL, updated_at TEXT)');
                $pdo->exec('INSERT INTO runtime_write_gate (id, blocked, version) VALUES (1, 0, '.($database === self::SOURCE ? 7 : 1).')');
                $pdo->exec('CREATE TABLE digital_orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL, reservation_active INTEGER NOT NULL DEFAULT 0)');
                $pdo->exec('CREATE TABLE digital_provider_attempts (id INTEGER PRIMARY KEY, order_id INTEGER, active_order_id INTEGER, status TEXT NOT NULL)');
                $databases[$database] = $pdo;
            }

            return new SQLiteConnection($databases[$database], $database, '', $config + ['name' => $name]);
        });
        $platform = new class($paths) extends CpanelRestorePlatform
        {
            public function __construct(private array $fixturePaths) {}

            protected function paths(): array
            {
                return $this->fixturePaths;
            }
        };

        return compact('platform', 'paths', 'content', 'databases');
    }

    private function processes(array $fixture, ?callable $override = null): void
    {
        Process::preventStrayProcesses();
        Process::fake(function (PendingProcess $process) use ($fixture, $override) {
            if ($override && ($result = $override($process)) !== null) {
                return $result;
            }
            $arguments = $process->command;
            if (($arguments[2] ?? null) === 'Mysql') {
                return Process::result(json_encode(['result' => ['status' => 1, 'data' => []]], JSON_THROW_ON_ERROR));
            }
            if (($arguments[1] ?? null) === '-r') {
                return Process::result(json_encode(['ready' => true, 'nonce' => $arguments[7]], JSON_THROW_ON_ERROR));
            }
            if (($arguments[2] ?? null) === 'down') {
                file_put_contents($fixture['paths']['maintenance'], '{"retry":30,"fixture":true}');
            } elseif (($arguments[2] ?? null) === 'up') {
                if (is_file($fixture['paths']['maintenance'])) {
                    unlink($fixture['paths']['maintenance']);
                }
            } elseif (($arguments[2] ?? null) !== 'config:cache') {
                throw new RuntimeException('Unexpected external fixture process.');
            }

            return Process::result();
        });
    }

    private function rejects(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Unsafe restore action accepted.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString($message, $failure->getMessage());
            $this->assertStringNotContainsString('PRIVATE_FIXTURE_', $failure->getMessage());
        }
    }

    public function test_capabilities_reject_noncanonical_release_before_any_external_process_or_environment_write(): void
    {
        $fixture = $this->fixture();
        config(['backups.runtime_root' => dirname($fixture['paths']['environment'])]);
        Process::fake();
        Process::preventStrayProcesses();

        $capabilities = (new CpanelRestorePlatform)->capabilities();

        $this->assertFalse($capabilities['prepare']);
        $this->assertFalse($capabilities['switch']);
        $this->assertStringContainsString('مسارات', $capabilities['reason']);
        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        Process::assertNothingRan();
    }

    public function test_db_url_duplicates_wrong_default_remote_host_and_environment_mismatch_reject_before_prepare(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        foreach (["DB_URL=mysql://PRIVATE_FIXTURE_URL\n", 'export DB_DATABASE=dananiriq_other'."\n"] as $addition) {
            file_put_contents($fixture['paths']['environment'], $fixture['content'].$addition);
            $this->rejects(fn () => $fixture['platform']->createScratch(self::JOB), 'ملف البيئة');
        }
        file_put_contents($fixture['paths']['environment'], $fixture['content']);
        foreach ([['database.default' => 'sqlite'], ['database.connections.mysql.url' => 'mysql://PRIVATE_FIXTURE_URL'], ['database.connections.mysql.host' => 'other-server'], ['database.connections.mysql.database' => 'dananiriq_wrong']] as $change) {
            $old = config()->get(array_key_first($change));
            config($change);
            $this->assertFalse($fixture['platform']->capabilities()['prepare']);
            config([array_key_first($change) => $old]);
        }
        $this->rejects(fn () => $fixture['platform']->createScratch('../.env'), 'معرف');

        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        Process::assertNothingRan();
    }

    public function test_scratch_uses_new_cpanel_database_and_only_existing_user_without_shell_or_secret_arguments(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);

        $scratch = $fixture['platform']->createScratch(self::JOB);

        $this->assertMatchesRegularExpression('/^dananiriq_mr_[a-f0-9]{12}$/D', $scratch['identifier']);
        $this->assertSame($scratch['identifier'], $scratch['connection']['database']);
        $this->assertNull($scratch['connection']['url']);
        $this->assertSame('dananiriq_testuser', $scratch['connection']['username']);
        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        Process::assertRanInOrder([
            [PHP_BINARY, '--output=json', 'Mysql', 'list_databases'],
            [PHP_BINARY, '--output=json', 'Mysql', 'create_database', 'name='.$scratch['identifier']],
            [PHP_BINARY, '--output=json', 'Mysql', 'set_privileges_on_database', 'user=dananiriq_testuser', 'database='.$scratch['identifier'], 'privileges=ALL PRIVILEGES'],
        ]);
    }

    public function test_existing_scratch_and_cpanel_failure_never_recreate_or_delete_databases_and_do_not_echo_errors(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[3] ?? null) === 'list_databases'
            ? Process::result(json_encode(['result' => ['status' => 1, 'data' => [['database' => $scratch['identifier']]]]], JSON_THROW_ON_ERROR)) : null);

        $this->rejects(fn () => $fixture['platform']->createScratch(self::JOB), 'موجودة');
        Process::assertRanTimes([PHP_BINARY, '--output=json', 'Mysql', 'create_database', 'name='.$scratch['identifier']], 1);
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[3] ?? null) === 'create_database'
            ? Process::result('{"result":{"status":0,"errors":["PRIVATE_FIXTURE_PASSWORD"]}}') : null);
        $this->rejects(fn () => $fixture['platform']->createScratch(self::OTHER_JOB), 'رفضت');
        Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[3] ?? '', ['delete_database', 'delete_user'], true));
    }

    public function test_maintenance_claims_real_gate_version_and_prevents_foreign_jobs_from_releasing_its_maintenance(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);

        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);

        $this->assertSame(self::JOB, $maintenance['rollback']['job_id']);
        $this->assertSame(8, DB::table('runtime_write_gate')->value('version'));
        $this->assertSame(self::JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertFileExists($fixture['paths']['maintenance']);
        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::OTHER_JOB), 'عملية أخرى');
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch(['job_id' => self::OTHER_JOB, 'state_hash' => $maintenance['rollback']['state_hash']]), 'عملية أخرى');
        $this->assertSame(self::JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[2] ?? '') === 'down', 1);
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? '') === 'up');
    }

    public function test_unresolved_digital_orders_reject_maintenance_before_any_gate_environment_or_process_change(): void
    {
        foreach ([['pending', 1], ['review', 0], ['succeeded', 1]] as [$status, $held]) {
            $fixture = $this->fixture();
            $this->processes($fixture);
            DB::table('digital_orders')->insert(['id' => 1, 'status' => $status, 'reservation_active' => $held]);

            $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'شراء رقمية غير محسومة');

            $this->assertSame(7, DB::table('runtime_write_gate')->value('version'));
            $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
            $this->assertNull(DB::table('runtime_write_gate')->value('restore_job_id'));
            $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
            $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
            $this->assertFileDoesNotExist($fixture['paths']['folder'].'/'.self::JOB.'.env.before');
            $this->assertSame('', file_get_contents($fixture['paths']['folder'].'/active.lock'));
            Process::assertNothingRan();
        }
    }

    public function test_inflight_provider_attempt_blocks_even_terminal_order_while_resolved_unknown_history_is_not_reanimated(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        DB::table('digital_orders')->insert(['id' => 1, 'status' => 'failed', 'reservation_active' => 0]);
        DB::table('digital_provider_attempts')->insert(['id' => 1, 'order_id' => 1, 'active_order_id' => 1, 'status' => 'unknown']);
        $checks = 0;
        DB::connection('mysql')->listen(function (QueryExecuted $event) use (&$checks): void {
            if (str_contains($event->sql, 'digital_provider_attempts') && str_contains($event->sql, 'active_order_id') && str_starts_with($event->sql, 'select exists')) {
                $this->assertSame(1, $event->connection->transactionLevel());
                $checks++;
            }
        });

        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'شراء رقمية غير محسومة');

        $this->assertSame(1, $checks);
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(7, DB::table('runtime_write_gate')->value('version'));
        Process::assertNothingRan();
        DB::table('digital_provider_attempts')->where('id', 1)->update(['active_order_id' => null]);

        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);

        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame('unknown', DB::table('digital_provider_attempts')->value('status'));
        $this->assertSame('failed', DB::table('digital_orders')->value('status'));
        $fixture['platform']->rollbackSwitch($maintenance['rollback']);
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
    }

    public function test_pending_digital_scratch_is_rejected_before_claiming_target_or_switching_environment(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        config(['database.connections.pending_digital_fixture' => $scratch['connection']]);
        $target = DB::connection('pending_digital_fixture');
        $target->table('digital_orders')->insert(['id' => 4, 'status' => 'review', 'reservation_active' => 1]);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);

        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $scratch), 'شراء رقمية غير محسومة');

        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        $this->assertSame(self::SOURCE, DB::connection('mysql')->getDatabaseName());
        $this->assertSame(1, $target->table('runtime_write_gate')->value('version'));
        $this->assertFalse((bool) $target->table('runtime_write_gate')->value('blocked'));
        $this->assertFileExists($fixture['paths']['maintenance']);
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? '') === 'config:cache');
        $fixture['platform']->rollbackSwitch($maintenance['rollback']);
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame('review', $target->table('digital_orders')->value('status'));
    }

    public function test_exclusive_process_lock_rejects_begin_without_taking_down_or_claiming_database(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $handle = fopen($fixture['paths']['folder'].'/active.lock', 'c+');
        flock($handle, LOCK_EX | LOCK_NB);
        try {
            $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'القفل الحصري');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(7, DB::table('runtime_write_gate')->value('version'));
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
        Process::assertNothingRan();
    }

    public function test_lost_commit_acknowledgement_recovers_only_confirmed_owned_gate_instead_of_abandoning_lock(): void
    {
        $pdo = new class extends PDO
        {
            public bool $failAfterCommit = false;

            public function __construct()
            {
                parent::__construct('sqlite::memory:');
            }

            public function commit(): bool
            {
                $committed = parent::commit();
                if ($this->failAfterCommit) {
                    $this->failAfterCommit = false;
                    throw new RuntimeException('Lost fixture commit acknowledgement.');
                }

                return $committed;
            }
        };
        $fixture = $this->fixture($pdo);
        $this->processes($fixture);
        $pdo->failAfterCommit = true;

        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'Lost fixture commit');

        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(9, DB::table('runtime_write_gate')->value('version'));
        $this->assertSame('', file_get_contents($fixture['paths']['folder'].'/active.lock'));
        $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[2] ?? '') === 'up', 1);
    }

    public function test_verified_switch_atomically_changes_only_database_scrubs_child_env_and_health_checks_before_up(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);

        $switched = $fixture['platform']->switchVerified(self::JOB, $scratch);

        $this->assertSame($maintenance, $switched);
        $this->assertSame(str_replace('DB_DATABASE='.self::SOURCE, 'DB_DATABASE='.$scratch['identifier'], $fixture['content']), file_get_contents($fixture['paths']['environment']));
        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['folder'].'/'.self::JOB.'.env.before'));
        $this->assertFileDoesNotExist($fixture['paths']['environment'].'.restore-'.self::JOB);
        $this->assertSame($scratch['identifier'], DB::connection()->getDatabaseName());
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        Process::assertRan(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'config:cache'
            && $process->environment['DB_DATABASE'] === false && $process->environment['DB_URL'] === false && $process->environment['APP_KEY'] === false);
        Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === '-r' && $process->command[4] === $scratch['identifier'] && $process->command[5] === self::JOB && $process->command[6] === '2');
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'up');

        $fixture['platform']->finishMaintenance(self::JOB);

        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertNull(DB::table('runtime_write_gate')->value('restore_job_id'));
        $this->assertSame(3, DB::table('runtime_write_gate')->value('version'));
        $this->assertSame('', file_get_contents($fixture['paths']['folder'].'/active.lock'));
        $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[1] ?? null) === '-r', 2);
    }

    public function test_failed_fresh_process_health_keeps_target_and_source_blocked_until_verified_rollback(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[1] ?? null) === '-r' ? Process::result('PRIVATE_FIXTURE_PASSWORD', exitCode: 5) : null);

        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $scratch), 'عملية PHP جديدة');

        $this->assertFileExists($fixture['paths']['maintenance']);
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame($scratch['identifier'], DB::connection()->getDatabaseName());
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'up');
        $this->processes($fixture);
        $fixture['platform']->rollbackSwitch($maintenance['rollback']);
        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        $this->assertSame(self::SOURCE, DB::connection()->getDatabaseName());
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(9, DB::table('runtime_write_gate')->value('version'));
        $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
    }

    public function test_false_positive_health_output_and_failed_config_cache_do_not_open_system_and_allow_owned_rollback(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[2] ?? null) === 'config:cache' ? Process::result(exitCode: 1) : null);

        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $scratch), 'تعذر تحديث');

        $this->assertFileExists($fixture['paths']['maintenance']);
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[1] ?? null) === '-r' ? Process::result('{"ready":true,"nonce":"other_process"}') : null);
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($maintenance['rollback']), 'عملية PHP جديدة');
        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'up');
        $this->processes($fixture);
        $fixture['platform']->rollbackSwitch($maintenance['rollback']);
        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertFileDoesNotExist($fixture['paths']['maintenance']);
    }

    public function test_scratch_credentials_cannot_redirect_switch_and_completed_job_cannot_roll_back_later_job(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);
        $foreign = $scratch;
        $foreign['connection']['host'] = 'other-server';

        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $foreign), 'لا تطابق');

        $this->assertSame($fixture['content'], file_get_contents($fixture['paths']['environment']));
        $fixture['platform']->switchVerified(self::JOB, $scratch);
        $fixture['platform']->finishMaintenance(self::JOB);
        $fixture['platform']->beginMaintenance(self::OTHER_JOB);
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($maintenance['rollback']), 'عملية أخرى');
        $this->assertSame(self::OTHER_JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        $this->assertFileExists($fixture['paths']['maintenance']);
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'up', 1);
    }

    public function test_changed_environment_or_stolen_gate_reject_switch_without_replacing_settings_or_releasing_writes(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        $scratch = $fixture['platform']->createScratch(self::JOB);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);
        file_put_contents($fixture['paths']['environment'], $fixture['content']."EXTERNAL_CHANGE=protected\n");

        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $scratch), 'لا تطابق');
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($maintenance['rollback']), 'تغيرت البيئة');
        $this->assertStringContainsString('EXTERNAL_CHANGE=protected', file_get_contents($fixture['paths']['environment']));
        file_put_contents($fixture['paths']['environment'], $fixture['content']);
        DB::table('runtime_write_gate')->where('id', 1)->update(['version' => 99]);
        $this->rejects(fn () => $fixture['platform']->switchVerified(self::JOB, $scratch), 'إصدار بوابة');
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertFileExists($fixture['paths']['maintenance']);
        Process::assertDidntRun(fn (PendingProcess $process) => in_array($process->command[2] ?? '', ['up', 'config:cache'], true));
    }

    public function test_partial_down_failure_rolls_back_only_owned_gate_and_health_failure_during_rollback_stays_closed(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture, function (PendingProcess $process) use ($fixture) {
            if (($process->command[2] ?? null) === 'down') {
                file_put_contents($fixture['paths']['maintenance'], '{"retry":30,"partial":true}');

                return Process::result(exitCode: 1);
            }

            return null;
        });

        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'تعذر تحديث');

        $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(9, DB::table('runtime_write_gate')->value('version'));
        $this->assertSame('', file_get_contents($fixture['paths']['folder'].'/active.lock'));
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[1] ?? '') === '-r', 1);
        $this->processes($fixture);
        $maintenance = $fixture['platform']->beginMaintenance(self::OTHER_JOB);
        $this->processes($fixture, fn (PendingProcess $process) => ($process->command[1] ?? null) === '-r' ? Process::result(exitCode: 4) : null);
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($maintenance['rollback']), 'عملية PHP جديدة');
        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(self::OTHER_JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        $this->assertFileExists($fixture['paths']['maintenance']);
        $this->assertStringContainsString(self::OTHER_JOB, file_get_contents($fixture['paths']['folder'].'/active.lock'));
        Process::assertRanTimes(fn (PendingProcess $process) => ($process->command[2] ?? '') === 'up', 1);
    }

    public function test_prior_maintenance_foreign_gate_and_tampered_rollback_never_raise_other_maintenance(): void
    {
        $fixture = $this->fixture();
        $this->processes($fixture);
        file_put_contents($fixture['paths']['maintenance'], '{"external":true}');
        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'صيانة قائمة');
        unlink($fixture['paths']['maintenance']);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => self::OTHER_JOB]);
        $this->rejects(fn () => $fixture['platform']->beginMaintenance(self::JOB), 'عملية أخرى');
        $this->assertSame(self::OTHER_JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => false, 'restore_job_id' => null]);
        $maintenance = $fixture['platform']->beginMaintenance(self::JOB);
        $bad = array_replace($maintenance['rollback'], ['state_hash' => str_repeat('0', 64)]);
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($bad), 'بصمته');
        file_put_contents($fixture['paths']['maintenance'], '{"external":true}');
        $this->rejects(fn () => $fixture['platform']->rollbackSwitch($maintenance['rollback']), 'تغيرت الصيانة');

        $this->assertTrue((bool) DB::table('runtime_write_gate')->value('blocked'));
        $this->assertSame(self::JOB, DB::table('runtime_write_gate')->value('restore_job_id'));
        Process::assertDidntRun(fn (PendingProcess $process) => ($process->command[2] ?? null) === 'up');
    }

    public function test_native_canonical_release_env_and_storage_links_are_accepted_without_touching_real_release(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Canonical cPanel symlink acceptance runs in the private Linux shadow; Windows lacks file-symlink privileges.');
        }
        $fixture = $this->fixture();
        $root = dirname($fixture['paths']['environment']);
        mkdir($root.'/shared', 0700);
        mkdir($root.'/shared/storage', 0700);
        mkdir($root.'/releases', 0700);
        mkdir($root.'/releases/fixture', 0700);
        rename($fixture['paths']['environment'], $root.'/shared/.env');
        symlink($root.'/shared/.env', $root.'/releases/fixture/.env');
        $oldBase = app()->basePath();
        $oldStorage = app()->storagePath();
        $oldEnvironmentPath = app()->environmentPath();
        $oldEnvironmentFile = app()->environmentFile();
        app()->setBasePath($root.'/releases/fixture');
        app()->useStoragePath($root.'/shared/storage');
        app()->useEnvironmentPath($root.'/releases/fixture');
        app()->loadEnvironmentFrom('.env');
        config(['backups.runtime_root' => $root]);
        Process::fake();
        Process::preventStrayProcesses();
        try {
            $this->assertSame(['prepare' => true, 'switch' => true, 'reason' => null], (new CpanelRestorePlatform)->capabilities());
            mkdir($root.'/outside', 0700);
            rmdir($root.'/.local/restore');
            symlink($root.'/outside', $root.'/.local/restore');
            $this->assertFalse((new CpanelRestorePlatform)->capabilities()['prepare']);
            unlink($root.'/.local/restore');
            $this->assertSame($fixture['content'], file_get_contents($root.'/shared/.env'));
            Process::assertNothingRan();
        } finally {
            unlink($root.'/releases/fixture/.env');
            app()->setBasePath($oldBase);
            app()->useStoragePath($oldStorage);
            app()->useEnvironmentPath($oldEnvironmentPath);
            app()->loadEnvironmentFrom($oldEnvironmentFile);
        }

    }
}
