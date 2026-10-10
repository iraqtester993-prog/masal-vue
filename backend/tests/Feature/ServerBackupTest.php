<?php

namespace Tests\Feature;

use App\Contracts\Backups\RestorePlatform;
use App\Enums\AccountType;
use App\Models\AccountMembership;
use App\Models\Backups\BackupPreview;
use App\Models\Backups\RestoreJob;
use App\Models\Backups\ServerBackup;
use App\Models\Company\CompanyAsset;
use App\Models\Digital\DigitalOrder;
use App\Models\Digital\ProviderAttempt;
use App\Models\PermissionProfile;
use App\Models\User;
use App\Services\Backups\BackupAccess;
use App\Services\Backups\BackupWorkflow;
use App\Services\Backups\SnapshotArchive;
use App\Services\Backups\SnapshotSchema;
use App\Services\Finance\Ledger;
use App\Services\ManagementAuthority;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class ServerBackupTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withHeader('X-Masal-Portal', 'admin')->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.(crc32($this->name()) % 200 + 1)]);
    }

    private function actor(): User
    {
        return $this->userFor($this->account(AccountType::System));
    }

    public function test_table_enumeration_is_confined_to_the_selected_mysql_database(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $builder = \Mockery::mock(Builder::class);
            $builder->shouldReceive('getTables')->once()->with('tenant_source')->andReturn([]);
            $connection = \Mockery::mock(Connection::class);
            $connection->shouldReceive('getSchemaBuilder')->once()->andReturn($builder);
            $connection->shouldReceive('getDriverName')->andReturn($driver);
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('tenant_source');
            $connection->shouldReceive('table')->once()->with('migrations')->andReturn(DB::table('migrations'));
            $this->assertSame([], app(SnapshotSchema::class)->describe($connection)['tables']);
        }
    }

    public function test_sqlite_attached_databases_are_excluded_from_the_snapshot(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite attachment boundary is covered on SQLite.');
        }
        config(['database.connections.backup_schema_boundary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('backup_schema_boundary');
        try {
            $db->statement('CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT)');
            $db->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
            $db->statement("ATTACH DATABASE ':memory:' AS backup_neighbor");
            $db->statement('CREATE TABLE backup_neighbor.external_secret (id INTEGER PRIMARY KEY, credential TEXT)');
            $db->statement("INSERT INTO backup_neighbor.external_secret VALUES (1,'NEIGHBOR_SECRET')");
            $tables = app(SnapshotSchema::class)->describe($db)['tables'];
            $this->assertArrayHasKey('accounts', $tables);
            $this->assertArrayNotHasKey('external_secret', $tables);
        } finally {
            DB::purge('backup_schema_boundary');
            config(['database.connections.backup_schema_boundary' => null]);
        }
    }

    public function test_signed_snapshot_encrypts_business_secrets_streams_referenced_files_and_excludes_runtime_credentials(): void
    {
        $actor = $this->actor();
        $main = $this->account(AccountType::MainAgent, $actor->membership->account);
        $assetId = (string) Str::uuid();
        $assetPath = 'company/assets/'.$assetId.'.png';
        Storage::disk('local')->put($assetPath, str_repeat('PRIVATE_IMAGE_BYTES', 8000));
        CompanyAsset::factory()->create(['id' => $assetId, 'user_id' => $actor->id, 'path' => $assetPath]);
        DB::table('sessions')->insert(['id' => 'PRIVATE_SESSION', 'user_id' => $actor->id, 'payload' => 'PRIVATE_SESSION_PAYLOAD', 'last_activity' => time()]);
        $backup = app(BackupWorkflow::class)->create($actor);
        $this->assertSame('completed', $backup->status);
        $this->assertGreaterThan(0, $backup->bytes);
        $content = Storage::disk('local')->get($backup->path);
        foreach ([$actor->email, $actor->password, 'PRIVATE_IMAGE_BYTES', 'PRIVATE_SESSION', config('app.key'), '.env'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $seenRows = 0;
        $seenChunks = 0;
        $verified = app(SnapshotArchive::class)->read($backup->path, function (array $record) use (&$seenRows, &$seenChunks): void {
            $seenRows += $record['kind'] === 'row' ? 1 : 0;
            $seenChunks += $record['kind'] === 'file-chunk' ? 1 : 0;
        });
        $this->assertGreaterThan(2, $seenRows);
        $this->assertGreaterThan(1, $seenChunks);
        $this->assertSame(2, $verified['manifest']['counts']['accounts']);
        $this->assertSame(1, $verified['manifest']['files']);
        foreach (['sessions', 'personal_access_tokens', 'sales_device_sessions', 'server_backups', 'backup_previews', 'restore_jobs'] as $table) {
            $this->assertArrayNotHasKey($table, $verified['manifest']['counts']);
        }
        $preview = app(BackupWorkflow::class)->preview($actor, null, $backup->id);
        $this->assertTrue($preview->reference_verified);
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('backups/validation'));
    }

    public function test_preview_rejects_unsigned_legacy_json_tampering_truncation_and_other_application_keys(): void
    {
        $actor = $this->actor();
        $backup = app(BackupWorkflow::class)->create($actor);
        $original = Storage::disk('local')->get($backup->path);
        $bad = ['legacy' => json_encode(['version' => 1, 'settings' => [], 'agents' => []]), 'truncated' => substr($original, 0, strrpos($original, "\n", -2) + 1), 'tampered' => str_replace('"version":1', '"version":2', $original), 'trailing' => $original."{}\n"];
        foreach ($bad as $name => $content) {
            $file = UploadedFile::fake()->createWithContent($name.'.json', $content);
            try {
                app(BackupWorkflow::class)->preview($actor, $file, null);
                $this->fail('Invalid snapshot accepted: '.$name);
            } catch (\RuntimeException $failure) {
                $this->assertNotSame('', $failure->getMessage());
            }
        }
        $this->assertSame(0, BackupPreview::count());
        $this->assertSame([], Storage::disk('local')->allFiles('backups/previews'));
        $this->assertSame(1, DB::table('accounts')->count());
        $originalKey = config('app.key');
        try {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            Crypt::clearResolvedInstance('encrypter');
            app()->forgetInstance('encrypter');
            try {
                app(SnapshotArchive::class)->read($backup->path);
                $this->fail('Snapshot from a different key was accepted');
            } catch (\RuntimeException $failure) {
                $this->assertStringContainsString('مختلف', $failure->getMessage());
            }
        } finally {
            config(['app.key' => $originalKey]);
            Crypt::clearResolvedInstance('encrypter');
            app()->forgetInstance('encrypter');
        }
    }

    public function test_system_employee_and_agent_cannot_backup_even_with_individual_grants_and_owner_permission_denial_is_honored(): void
    {
        $owner = $this->actor();
        $main = $this->account(AccountType::MainAgent, $owner->membership->account);
        $agent = $this->userFor($main);
        foreach (['backup.view', 'backup.create', 'backup.restore'] as $key) {
            DB::table('membership_permissions')->insert(['membership_id' => $agent->membership->id, 'permission_id' => DB::table('permissions')->where('name', $key)->value('id'), 'allowed' => true]);
        }
        $profile = PermissionProfile::factory()->create(['account_id' => $owner->membership->account_id]);
        app(ManagementAuthority::class)->replaceProfilePermissions($profile->id, ['account.view', 'backup.view', 'backup.create', 'backup.restore']);
        $employee = User::factory()->create();
        AccountMembership::create(['user_id' => $employee->id, 'account_id' => $owner->membership->account_id, 'kind' => 'employee', 'role_id' => DB::table('roles')->where('name', 'employee')->value('id'), 'permission_profile_id' => $profile->id, 'include_descendants' => false, 'status' => 'active']);
        DB::table('membership_scope_roots')->insert(['membership_id' => $employee->membership->id, 'account_id' => $main->id]);
        foreach ([$agent, $employee] as $user) {
            try {
                app(BackupAccess::class)->require($user->fresh(), 'backup.view');
                $this->fail('Backup scope leaked');
            } catch (HttpException $failure) {
                $this->assertSame(403, $failure->getStatusCode());
            }
        }
        DB::table('membership_permissions')->insert(['membership_id' => $owner->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'backup.create')->value('id'), 'allowed' => false]);
        $this->asPortalUser($owner);
        $this->postJson('/api/v1/backups')->assertForbidden();
    }

    public function test_api_creates_downloads_and_previews_real_snapshot_without_paths_or_config_exposure(): void
    {
        $actor = $this->actor();
        $this->asPortalUser($actor);
        $created = $this->postJson('/api/v1/backups')->assertCreated()->assertJsonPath('data.status', 'completed');
        $id = $created->json('data.id');
        $this->assertStringNotContainsString('backups/snapshots', $created->getContent());
        $this->get('/api/v1/backups/'.$id.'/download')->assertOk()->assertHeader('content-type', 'application/octet-stream');
        $preview = $this->postJson('/api/v1/backups/previews', ['backup_id' => $id])->assertCreated()->assertJsonPath('data.reference_verified', true);
        $this->getJson('/api/v1/backups')->assertOk()->assertJsonPath('capabilities.switch', false);
        $this->postJson('/api/v1/backups/restore-jobs', ['preview_id' => $preview->json('data.id'), 'version' => 1, 'current_password' => 'wrong'])->assertUnprocessable();
        $actor->update(['password' => 'actual-password']);
        $this->postJson('/api/v1/backups/restore-jobs', ['preview_id' => $preview->json('data.id'), 'version' => 1, 'current_password' => 'actual-password'])->assertConflict();
        $this->assertSame(0, RestoreJob::count());
        $this->assertSame(1, DB::table('accounts')->count());
    }

    public function test_missing_assets_fail_backup_instead_of_claiming_completed_and_paths_cannot_escape_private_assets(): void
    {
        $actor = $this->actor();
        CompanyAsset::factory()->create(['user_id' => $actor->id, 'path' => 'company/assets/'.Str::uuid().'.png']);
        try {
            app(BackupWorkflow::class)->create($actor);
            $this->fail('Missing attachment accepted');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('غير موجود', $failure->getMessage());
        }
        $this->assertSame('failed', ServerBackup::first()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('backups/snapshots'));
        foreach (['../.env', 'company/assets/../../.env', 'C:/secret', 'backups/snapshots/a.json', 'company/assets/.env'] as $path) {
            try {
                app(SnapshotSchema::class)->safeAsset($path);
                $this->fail('Unsafe path accepted: '.$path);
            } catch (\RuntimeException $failure) {
                $this->assertNotSame('', $failure->getMessage());
            }
        }
    }

    public function test_restore_replays_balanced_ledger_preserves_wallet_versions_and_immutable_guards_without_source_writes(): void
    {
        $actor = $this->actor();
        $main = $this->account(AccountType::MainAgent, $actor->membership->account);
        $ledger = app(Ledger::class);
        DB::transaction(function () use ($ledger, $actor, $main): void {
            $debit = $ledger->wallet($actor->membership->account_id, 'cash', 'IQD', 'external');
            $credit = $ledger->wallet($main->id, 'cash', 'IQD');
            $ledger->pair($actor, 'funding', (string) Str::uuid(), ['actual' => true], $debit, $credit, 10000, 'actual');
        });
        $backup = app(BackupWorkflow::class)->create($actor);
        $before = DB::table('finance_wallets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $preview = app(BackupWorkflow::class)->preview($actor, null, $backup->id);
        $this->assertTrue($preview->reference_verified);
        $this->assertSame($before, DB::table('finance_wallets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, DB::table('finance_transactions')->count());
        $this->assertSame(2, DB::table('finance_entries')->count());
    }

    public function test_unresolved_provider_purchases_reject_restore_preview_without_mutating_source_or_reissuing_purchase(): void
    {
        $actor = $this->actor();
        $order = DigitalOrder::factory()->create(['status' => 'review', 'reservation_active' => true]);
        ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $actor->id, 'status' => 'unknown', 'active_order_id' => null]);
        $backup = app(BackupWorkflow::class)->create($actor);
        try {
            app(BackupWorkflow::class)->preview($actor, null, $backup->id);
            $this->fail('Unresolved provider purchase was accepted for restoration.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('رقمية', $failure->getMessage());
        }
        $this->assertDatabaseHas('digital_orders', ['id' => $order->id, 'status' => 'review', 'reservation_active' => true]);
        $this->assertSame(1, DB::table('digital_provider_attempts')->count());
        $this->assertSame(0, BackupPreview::count());
        $this->assertSame(0, RestoreJob::count());
        $this->assertSame([], Storage::disk('local')->allFiles('backups/validation'));
    }

    public function test_historical_unknown_attempt_resolved_by_terminal_order_can_restore_but_open_attempt_cannot(): void
    {
        $actor = $this->actor();
        $order = DigitalOrder::factory()->create(['status' => 'succeeded', 'reservation_active' => false, 'actual_cost_minor' => 430000, 'actual_retail_minor' => 500000, 'cost_basis' => 'provider_response', 'company_transaction_id' => fake()->uuid(), 'receipt_ref' => fake()->uuid(), 'resolved_at' => now(), 'receipt' => ['pin' => 'PRIVATE_PIN']]);
        ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $actor->id, 'status' => 'unknown', 'active_order_id' => null, 'finished_at' => now()]);
        $backup = app(BackupWorkflow::class)->create($actor);
        $this->assertTrue(app(BackupWorkflow::class)->preview($actor, null, $backup->id)->reference_verified);
        ProviderAttempt::factory()->create(['order_id' => $order->id, 'actor_id' => $actor->id, 'active_order_id' => $order->id, 'status' => 'dispatching']);
        $unsafe = app(BackupWorkflow::class)->create($actor);
        try {
            app(BackupWorkflow::class)->preview($actor, null, $unsafe->id);
            $this->fail('In-flight provider attempt was accepted for restoration.');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('جارية', $failure->getMessage());
        }
        $this->assertDatabaseHas('digital_orders', ['id' => $order->id, 'status' => 'succeeded']);
        $this->assertSame(2, DB::table('digital_provider_attempts')->count());
    }

    public function test_large_fields_are_streamed_in_bounded_encrypted_frames_and_schema_changes_reject_old_snapshot(): void
    {
        $actor = $this->actor();
        $details = json_encode(['large' => str_repeat('PRIVATE_LARGE_RECORD', 120000)], JSON_THROW_ON_ERROR);
        DB::table('audit_logs')->insert(['user_id' => $actor->id, 'account_id' => $actor->membership->account_id, 'subject_account_id' => null, 'action' => 'test.large', 'portal' => 'admin', 'ip_address' => '198.51.100.4', 'created_at' => now(), 'details' => $details]);
        $backup = app(BackupWorkflow::class)->create($actor);
        $actual = null;
        app(SnapshotArchive::class)->read($backup->path, function (array $record) use (&$actual): void {
            if ($record['kind'] === 'row' && $record['table'] === 'audit_logs' && $record['row']['action'] === 'test.large') {
                $actual = $record['row']['details'];
            }
        });
        $this->assertSame($details, $actual);
        $this->assertTrue(app(BackupWorkflow::class)->preview($actor, null, $backup->id)->reference_verified);
        Schema::create('backup_schema_test_extra', function ($table): void {
            $table->id();
        });
        try {
            app(BackupWorkflow::class)->preview($actor, null, $backup->id);
            $this->fail('Changed schema accepted');
        } catch (\RuntimeException $failure) {
            $this->assertStringContainsString('مخطط', $failure->getMessage());
        } finally {
            Schema::drop('backup_schema_test_extra');
        }
    }

    public function test_invalid_scope_reference_is_rejected_inside_scratch_without_modifying_source_or_leaking_sql(): void
    {
        $actor = $this->actor();
        $main = $this->account(AccountType::MainAgent, $actor->membership->account);
        DB::table('account_closure')->where('ancestor_id', $actor->membership->account_id)->where('descendant_id', $main->id)->delete();
        $backup = app(BackupWorkflow::class)->create($actor);
        $this->asPortalUser($actor);
        $response = $this->postJson('/api/v1/backups/previews', ['backup_id' => $backup->id])->assertUnprocessable();
        $this->assertStringContainsString('نطاق', $response->json('message'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame(0, BackupPreview::count());
        $this->assertSame([], Storage::disk('local')->allFiles('backups/validation'));
    }

    private function withCommittedWorkerSource(callable $callback): void
    {
        $original = DB::getDefaultConnection();
        $name = 'backup_worker_source_'.Str::uuid();
        $reader = $name.'_reader';
        $folder = 'backups/worker-source/'.$name;
        Storage::disk('local')->makeDirectory($folder);
        $path = Storage::disk('local')->path($folder.'/source.sqlite');
        touch($path);
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]]);
        try {
            DB::setDefaultConnection($name);
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => $name, '--force' => true]));
            config(['database.connections.'.$reader => ['name' => $reader] + DB::connection($name)->getConfig()]);
            $callback(DB::connection($reader));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($name);
            DB::purge($reader);
            config(['database.connections.'.$name => null]);
            config(['database.connections.'.$reader => null]);
            Storage::disk('local')->deleteDirectory($folder);
        }
    }

    private function platform(bool $failSwitch = false): RestorePlatform
    {
        $folder = 'backups/platform-test/'.Str::uuid();
        Storage::disk('local')->makeDirectory($folder);
        $platform = new class($folder, $failSwitch) implements RestorePlatform
        {
            public array $events = [];

            public ?array $scratch = null;

            public function __construct(private string $folder, private bool $failSwitch) {}

            public function capabilities(): array
            {
                return ['prepare' => true, 'switch' => true, 'reason' => null];
            }

            public function createScratch(string $jobId): array
            {
                $this->events[] = 'scratch';
                $path = Storage::disk('local')->path($this->folder.'/scratch.sqlite');
                touch($path);

                return $this->scratch = ['identifier' => 'test-'.$jobId, 'connection' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true]];
            }

            public function beginMaintenance(string $jobId): array
            {
                $this->events[] = 'maintenance';
                DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => $jobId, 'version' => DB::raw('version+1')]);

                return ['rollback' => ['test' => true]];
            }

            public function switchVerified(string $jobId, array $scratch): array
            {
                $this->events[] = 'switch';
                if ($this->failSwitch) {
                    throw new \RuntimeException('اختبار فشل التحويل قبل تغيير القاعدة');
                }
                $active = DB::getDefaultConnection();
                config(['database.connections.'.$active => ['name' => $active] + $scratch['connection']]);
                DB::purge($active);

                return ['rollback' => ['test' => true]];
            }

            public function rollbackSwitch(array $rollback): void
            {
                $this->events[] = 'rollback';
                DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => false, 'restore_job_id' => null, 'version' => DB::raw('version+1')]);
            }

            public function finishMaintenance(string $jobId): void
            {
                $this->events[] = 'finish';
                DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => false, 'restore_job_id' => null, 'version' => DB::raw('version+1')]);
            }
        };
        app()->instance('backup-test-platform', $platform);
        config(['backups.platform_class' => 'backup-test-platform']);

        return $platform;
    }

    public function test_reviewed_restore_worker_uses_new_database_and_safety_snapshot_before_switch_and_preserves_current_source(): void
    {
        $this->withCommittedWorkerSource(function (Connection $source): void {
            $actor = $this->actor();
            $actor->update(['password' => 'actual-password']);
            $actor->refresh();
            DB::table('user_presences')->insert(['user_id' => $actor->id, 'session_hash' => hash('sha256', 'old-session'), 'session_version' => $actor->session_version, 'connected' => true, 'sharing' => true, 'consent_version' => 4, 'latitude' => 33.3152, 'longitude' => 44.3661, 'accuracy' => 15.5, 'location_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $backup = app(BackupWorkflow::class)->create($actor);
            $preview = app(BackupWorkflow::class)->preview($actor, null, $backup->id);
            $this->account(AccountType::MainAgent, $actor->membership->account);
            $platform = $this->platform();
            $job = app(BackupWorkflow::class)->queue($actor, ['preview_id' => $preview->id, 'version' => 1, 'current_password' => 'actual-password']);
            $this->assertSame('queued', $job->status);
            $this->assertSame(2, $preview->fresh()->version);
            $result = app(BackupWorkflow::class)->run($job->id);
            $this->assertSame('completed', $result->status);
            $this->assertSame(DB::getDefaultConnection(), $result->getConnectionName());
            $this->assertSame('completed', $job->fresh()->status);
            $this->assertSame('completed', $source->table('restore_jobs')->where('id', $job->id)->value('status'));
            $this->assertSame(['scratch', 'maintenance', 'switch', 'finish'], $platform->events);
            $this->assertSame(2, $result->safety_backup_id ? ServerBackup::findOrFail($result->safety_backup_id)->manifest['counts']['accounts'] : null);
            $this->assertSame(2, $source->table('accounts')->count());
            $this->assertSame(1, DB::table('accounts')->count());
            $this->assertTrue((bool) $source->table('runtime_write_gate')->value('blocked'));
            $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
            $name = 'backup_test_completed';
            config(['database.connections.'.$name => $platform->scratch['connection']]);
            try {
                $scratch = DB::connection($name);
                $this->assertSame(1, $scratch->table('accounts')->count());
                $this->assertSame('completed', $scratch->table('restore_jobs')->where('id', $job->id)->value('status'));
                $this->assertSame(0, $scratch->table('sessions')->count());
                $this->assertSame(0, $scratch->table('runtime_write_gate')->value('blocked'));
                $presence = $scratch->table('user_presences')->first();
                $this->assertSame(0, (int) $presence->connected);
                $this->assertSame(0, (int) $presence->sharing);
                $this->assertSame(5, (int) $presence->consent_version);
                $this->assertEquals(33.3152, (float) $presence->latitude);
                $this->assertEquals(44.3661, (float) $presence->longitude);
                $this->assertSame((int) $actor->session_version + 1, (int) $scratch->table('users')->where('id', $actor->id)->value('session_version'));
                $this->assertNull($scratch->table('users')->where('id', $actor->id)->value('remember_token'));
                $this->assertNotSame('backup-test-platform', $result->getRawOriginal('scratch'));
                $this->assertArrayNotHasKey('scratch', $result->toArray());
                $this->assertArrayNotHasKey('rollback', $result->toArray());
            } finally {
                DB::purge($name);
                config(['database.connections.'.$name => null]);
            }
        });
    }

    public function test_restore_failure_after_safety_backup_invokes_rollback_and_retains_live_database_and_failure_status(): void
    {
        $this->withCommittedWorkerSource(function (): void {
            $actor = $this->actor();
            $actor->update(['password' => 'actual-password']);
            $backup = app(BackupWorkflow::class)->create($actor);
            $preview = app(BackupWorkflow::class)->preview($actor, null, $backup->id);
            $platform = $this->platform(true);
            $job = app(BackupWorkflow::class)->queue($actor, ['preview_id' => $preview->id, 'version' => 1, 'current_password' => 'actual-password']);
            try {
                app(BackupWorkflow::class)->run($job->id);
                $this->fail('Expected platform failure');
            } catch (\RuntimeException $failure) {
                $this->assertStringContainsString('فشل التحويل', $failure->getMessage());
            }
            $this->assertSame(['scratch', 'maintenance', 'switch', 'rollback'], $platform->events);
            $this->assertSame('failed', $job->fresh()->status);
            $this->assertNotNull($job->fresh()->safety_backup_id);
            $this->assertFalse((bool) DB::table('runtime_write_gate')->value('blocked'));
            $this->assertSame(1, DB::table('accounts')->count());
            $this->assertSame('actual-password', Hash::check('actual-password', $actor->fresh()->password) ? 'actual-password' : 'changed');
        });
    }

    public function test_write_gate_rejects_new_backup_and_preview_http_actions_without_creating_control_records(): void
    {
        $actor = $this->actor();
        $backup = app(BackupWorkflow::class)->create($actor);
        $this->asPortalUser($actor);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'restore_job_id' => (string) Str::uuid()]);
        $this->postJson('/api/v1/backups')->assertStatus(423);
        $this->postJson('/api/v1/backups/previews', ['backup_id' => $backup->id])->assertStatus(423);
        $this->assertSame(1, ServerBackup::count());
        $this->assertSame(0, BackupPreview::count());
        $this->assertSame(0, RestoreJob::count());
    }

    public function test_http_preview_returns_persisted_version_and_restore_queue_uses_that_returned_version(): void
    {
        $actor = $this->actor();
        $actor->update(['password' => 'actual-password']);
        $platform = $this->platform();
        $this->asPortalUser($actor);
        $backup = $this->postJson('/api/v1/backups')->assertCreated()->assertJsonPath('data.status', 'completed')->json('data');
        $preview = $this->postJson('/api/v1/backups/previews', ['backup_id' => $backup['id']])->assertCreated()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.reference_verified', true)->json('data');
        $this->assertSame($backup['sha256'], $preview['sha256']);
        $queued = $this->postJson('/api/v1/backups/restore-jobs', ['preview_id' => $preview['id'], 'version' => $preview['version'], 'current_password' => 'actual-password'])
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.failure', null)->assertJsonPath('data.completed_at', null)->assertJsonPath('data.safety_backup_id', null)->json('data');
        $this->getJson('/api/v1/backups/restore-jobs/'.$queued['id'])->assertOk()
            ->assertJsonPath('data.version', $queued['version'])->assertJsonPath('data.status', 'queued');
        $this->assertArrayNotHasKey('scratch', $queued);
        $this->assertArrayNotHasKey('rollback', $queued);
        $this->assertArrayNotHasKey('path', $preview);
        $this->assertSame(2, BackupPreview::findOrFail($preview['id'])->version);
        $this->assertDatabaseHas('restore_jobs', ['id' => $queued['id'], 'version' => 1, 'status' => 'queued']);
        $this->assertSame([], $platform->events);
        $this->assertSame(1, DB::table('accounts')->count());
    }

    public function test_restore_review_rejects_expired_and_stale_previews_and_current_password_is_rechecked_under_guard(): void
    {
        $actor = $this->actor();
        $actor->update(['password' => 'actual-password']);
        $backup = app(BackupWorkflow::class)->create($actor);
        $preview = app(BackupWorkflow::class)->preview($actor, null, $backup->id);
        $this->platform();
        $this->asPortalUser($actor);
        $payload = ['preview_id' => $preview->id, 'version' => 1, 'current_password' => 'actual-password'];
        $this->postJson('/api/v1/backups/restore-jobs', $payload + ['wrong' => true])->assertCreated();
        RestoreJob::query()->update(['status' => 'failed']);
        $this->postJson('/api/v1/backups/restore-jobs', $payload)->assertConflict();
        $preview->refresh()->update(['expires_at' => now()->subSecond()]);
        $payload['version'] = $preview->version;
        $this->postJson('/api/v1/backups/restore-jobs', $payload)->assertConflict();
        $this->assertSame(1, RestoreJob::count());
    }
}
