<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManagedPasswordResetRestoreMigrationTest extends TestCase
{
    public function test_regular_migration_seeds_the_permission_and_only_admin_and_agent_role_grants(): void
    {
        $this->withMigrationDatabase('none', function (Connection $connection): void {
            $this->assertTrue($connection->getSchemaBuilder()->hasTable('managed_password_reset_requests'));
            $this->assertTrue($connection->getSchemaBuilder()->hasColumn('users', 'password_reset_version'));
            $this->assertSame(702, $connection->table('permissions')->where('name', 'account.password_reset')->value('id'));
            $this->assertSame([501, 502], $connection->table('role_permissions')->where('permission_id', 702)->orderBy('role_id')->pluck('role_id')->all());
            $this->assertSame('account.view', $connection->table('permissions')->where('id', 701)->value('name'));
        });
    }

    public function test_fresh_restore_migration_creates_schema_without_inserting_permission_or_role_data(): void
    {
        $this->withMigrationDatabase('matching', function (Connection $connection): void {
            $this->assertTrue($connection->getSchemaBuilder()->hasTable('managed_password_reset_requests'));
            $this->assertTrue($connection->getSchemaBuilder()->hasColumn('users', 'password_change_required_at'));
            $this->assertSame([['id' => 701, 'name' => 'account.view']], $connection->table('permissions')->get()->map(fn ($row): array => (array) $row)->all());
            $this->assertSame(0, $connection->table('role_permissions')->count());
        });
    }

    public function test_restore_context_for_another_connection_does_not_skip_regular_permission_seeding(): void
    {
        $this->withMigrationDatabase('different', function (Connection $connection): void {
            $this->assertSame(702, $connection->table('permissions')->where('name', 'account.password_reset')->value('id'));
            $this->assertSame([501, 502], $connection->table('role_permissions')->where('permission_id', 702)->orderBy('role_id')->pluck('role_id')->all());
        });
    }

    private function withMigrationDatabase(string $context, callable $assertions): void
    {
        $original = DB::getDefaultConnection();
        $hadContext = app()->bound('masal.fresh_restore_database');
        $previousContext = $hadContext ? app('masal.fresh_restore_database') : null;
        $name = 'managed_reset_migration_'.str_replace('-', '', Str::uuid());
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $connection = DB::connection($name);
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame(':memory:', $connection->getDatabaseName());
        try {
            $schema = $connection->getSchemaBuilder();
            foreach (['accounts', 'users'] as $table) {
                $schema->create($table, function (Blueprint $table): void {
                    $table->id();
                });
            }
            foreach (['roles', 'permissions'] as $table) {
                $schema->create($table, function (Blueprint $table): void {
                    $table->id();
                    $table->string('name')->unique();
                });
            }
            $schema->create('role_permissions', function (Blueprint $table): void {
                $table->foreignId('role_id')->constrained('roles');
                $table->foreignId('permission_id')->constrained('permissions');
                $table->primary(['role_id', 'permission_id']);
            });
            $connection->table('permissions')->insert(['id' => 701, 'name' => 'account.view']);
            $connection->table('roles')->insert([
                ['id' => 501, 'name' => 'admin'], ['id' => 502, 'name' => 'agent'],
                ['id' => 503, 'name' => 'pos'], ['id' => 504, 'name' => 'employee'],
            ]);
            if ($context !== 'none') {
                app()->instance('masal.fresh_restore_database', $context === 'matching' ? $connection : DB::connection($original));
            }
            DB::setDefaultConnection($name);
            $migration = require database_path('migrations/2026_10_16_043152_add_managed_password_resets.php');
            $migration->up();
            $assertions($connection);
        } finally {
            app()->forgetInstance('masal.fresh_restore_database');
            if ($hadContext) {
                app()->instance('masal.fresh_restore_database', $previousContext);
            }
            DB::setDefaultConnection($original);
            DB::purge($name);
            config(['database.connections.'.$name => null]);
        }
    }
}
