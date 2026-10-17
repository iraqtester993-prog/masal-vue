<?php

namespace Tests\Feature;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class DigitalTotalsMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function maintenance(bool $active): void
    {
        $mode = Mockery::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturn($active);
        app()->instance(MaintenanceMode::class, $mode);
    }

    private function checkGate(): void
    {
        $migration = require database_path('migrations/2026_10_14_002408_create_digital_account_totals.php');
        (new ReflectionMethod($migration, 'assertMySqlQuiesced'))->invoke($migration, DB::connection());
    }

    public function test_active_runtime_is_rejected_before_any_rebuild(): void
    {
        $this->maintenance(false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires maintenance');
        $this->checkGate();
    }

    public function test_maintenance_without_blocked_write_gate_is_rejected(): void
    {
        $this->maintenance(true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('drained, blocked');
        $this->checkGate();
    }

    public function test_blocked_gate_is_checked_without_releasing_or_reassigning_it(): void
    {
        $this->maintenance(true);
        DB::table('runtime_write_gate')->where('id', 1)->update(['blocked' => true, 'version' => 17, 'restore_job_id' => '12345678-1234-1234-1234-123456789012']);
        $before = DB::table('runtime_write_gate')->first();
        $this->checkGate();
        $this->assertEquals($before, DB::table('runtime_write_gate')->first());
    }

    public function test_restore_bypass_is_scoped_to_the_exact_destination_connection(): void
    {
        $this->maintenance(false);
        app()->instance('masal.fresh_restore_database', DB::connection());
        $this->checkGate();
        app()->instance('masal.fresh_restore_database', new \stdClass);
        $this->expectException(RuntimeException::class);
        $this->checkGate();
    }
}
