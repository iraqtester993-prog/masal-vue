<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Backups\ServerBackup;
use App\Models\Company\CompanyAsset;
use App\Services\Backups\BackupCoordination;
use App\Services\Backups\BackupWorkflow;
use App\Services\Backups\SnapshotArchive;
use App\Services\Backups\SnapshotImporter;
use App\Services\Backups\SnapshotSchema;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

class BackupCoordinationTest extends TestCase
{
    use CreatesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['backups.coordination_path' => storage_path('framework/backup-coordination-test-'.getmypid().'.lock')]);
    }

    public function test_lock_path_has_a_safe_default_when_cached_configuration_predates_the_key(): void
    {
        $configuration = config('backups');
        unset($configuration['coordination_path']);
        config(['backups' => $configuration]);

        $this->assertSame(storage_path('framework/backup-coordination.lock'), app(BackupCoordination::class)->lockPath());
    }

    public function test_manual_and_scheduled_archives_do_not_retain_business_authorization_transactions(): void
    {
        $actor = $this->userFor($this->account(AccountType::System));
        $baseline = DB::transactionLevel();
        $this->partialMock(SnapshotArchive::class, function ($mock) use ($baseline): void {
            $mock->shouldReceive('create')->twice()->andReturnUsing(function () use ($baseline): array {
                $this->assertSame($baseline, DB::transactionLevel());

                return ['manifest' => [], 'sha256' => str_repeat('a', 64), 'bytes' => 1];
            });
        });
        $this->asPortalUser($actor);
        $this->postJson('/api/v1/backups')->assertCreated();
        $this->artisan('backups:create-scheduled')->assertSuccessful();
        $this->assertDatabaseCount('server_backups', 2);
    }

    public function test_revocation_during_archive_prevents_publication_and_preserves_failed_status(): void
    {
        $actor = $this->userFor($this->account(AccountType::System));
        $this->partialMock(SnapshotArchive::class, function ($mock) use ($actor): void {
            $mock->shouldReceive('create')->once()->andReturnUsing(function () use ($actor): array {
                DB::table('membership_permissions')->insert(['membership_id' => $actor->membership->id, 'permission_id' => DB::table('permissions')->where('name', 'backup.create')->value('id'), 'allowed' => false]);

                return ['manifest' => [], 'sha256' => str_repeat('a', 64), 'bytes' => 1];
            });
        });
        $this->asPortalUser($actor);
        $this->postJson('/api/v1/backups')->assertForbidden();
        $this->assertSame('failed', ServerBackup::sole()->status);
    }

    public function test_preview_validation_is_outside_authorization_transaction(): void
    {
        $actor = $this->userFor($this->account(AccountType::System));
        $backup = app(BackupWorkflow::class)->create($actor);
        $baseline = DB::transactionLevel();
        $this->partialMock(SnapshotImporter::class, function ($mock) use ($baseline): void {
            $mock->shouldReceive('preview')->once()->andReturnUsing(function () use ($baseline): array {
                $this->assertSame($baseline, DB::transactionLevel());

                return ['manifest' => [], 'reference_verified' => true];
            });
        });
        $this->asPortalUser($actor);
        $this->postJson('/api/v1/backups/previews', ['backup_id' => $backup->id])->assertCreated();
    }

    public function test_backup_lease_prevents_restore_and_defers_asset_deletion_without_waiting(): void
    {
        $service = app(BackupCoordination::class);
        $asset = 'catalog/products/old-image.png';
        Storage::disk('local')->put($asset, 'immutable-content');
        $service->read(function () use ($service, $asset): void {
            $handle = fopen($service->lockPath(), 'c+');
            try {
                $this->assertFalse(flock($handle, LOCK_EX | LOCK_NB));
            } finally {
                fclose($handle);
            }
            $service->deleteAsset($asset);
            $this->assertTrue(Storage::disk('local')->exists($asset));
            try {
                $service->restore(fn () => $this->fail('Restore cannot overlap an active backup.'));
                $this->fail('Expected coordination conflict.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        });
        $this->assertFalse(Storage::disk('local')->exists($asset));
        $service->restore(function () use ($service): void {
            $this->assertSame('safety', $service->read(fn () => 'safety'));
        });
    }

    public function test_deferred_cleanup_preserves_files_referenced_again_before_cleanup(): void
    {
        $actor = $this->userFor($this->account(AccountType::System));
        $asset = 'company/assets/referenced.png';
        Storage::disk('local')->put($asset, 'image');
        app(BackupCoordination::class)->read(function () use ($asset, $actor): void {
            app(BackupCoordination::class)->deleteAsset($asset);
            CompanyAsset::factory()->create(['user_id' => $actor->id, 'path' => $asset]);
        });
        $this->assertTrue(Storage::disk('local')->exists($asset));
    }

    public function test_file_database_archive_reads_one_snapshot_while_another_connection_commits(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite WAL proves the independent snapshot connection locally.');
        }
        $path = Storage::disk('local')->path('source.sqlite');
        touch($path);
        config(['database.connections.backup_wal_source' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
        $source = DB::connection('backup_wal_source');
        $source->statement('PRAGMA journal_mode=WAL');
        $source->statement('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $source->statement('CREATE TABLE alpha (id INTEGER PRIMARY KEY, value TEXT)');
        $source->statement('CREATE TABLE beta (id INTEGER PRIMARY KEY, value TEXT)');
        $source->table('alpha')->insert(['id' => 1, 'value' => 'before']);
        $source->table('beta')->insert(['id' => 1, 'value' => 'before']);
        $schema = new class($source) extends SnapshotSchema
        {
            public function __construct(private Connection $writer) {}

            public function rows(Connection $db, string $table, array $primary): \Generator
            {
                yield from parent::rows($db, $table, $primary);
                if ($table === 'alpha') {
                    $this->writer->table('beta')->where('id', 1)->update(['value' => 'after']);
                }
            }
        };
        try {
            $archive = new SnapshotArchive($schema);
            $archive->create($source, 'backups/snapshot-test.json');
            $rows = [];
            $archive->read('backups/snapshot-test.json', function (array $record) use (&$rows): void {
                if (($record['kind'] ?? '') === 'row') {
                    $rows[$record['table']][] = $record['row'];
                }
            });
            $this->assertSame('after', $source->table('beta')->value('value'));
            $this->assertSame('before', $rows['beta'][0]['value']);
            $this->assertSame(0, $source->transactionLevel());
        } finally {
            DB::purge('backup_wal_source');
            config(['database.connections.backup_wal_source' => null]);
        }
    }
}
