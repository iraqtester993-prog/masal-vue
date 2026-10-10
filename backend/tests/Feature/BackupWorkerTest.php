<?php

namespace Tests\Feature;

use App\Models\Backups\RestoreJob;
use App\Services\Backups\BackupWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BackupWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_queue_does_not_call_restore_workflow(): void
    {
        $this->mock(BackupWorkflow::class)->shouldNotReceive('run');
        $this->artisan('backups:work')->assertSuccessful();
    }

    public function test_one_run_processes_only_oldest_queued_job_and_does_not_replay_completed_jobs(): void
    {
        $completed = RestoreJob::factory()->create(['status' => 'completed', 'created_at' => now()->subDays(2)]);
        $first = RestoreJob::factory()->create(['created_at' => now()->subDay()]);
        $later = RestoreJob::factory()->create();
        $this->mock(BackupWorkflow::class)->shouldReceive('run')->once()->with($first->id)
            ->andReturnUsing(function (string $id): RestoreJob {
                $job = RestoreJob::findOrFail($id);
                $job->update(['status' => 'completed']);

                return $job;
            });
        $this->artisan('backups:work')->expectsOutput('Restore job '.$first->id.': completed.')->assertSuccessful();
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('queued', $later->fresh()->status);
    }

    public function test_failure_keeps_durable_workflow_state_does_not_run_next_job_and_redacts_exception(): void
    {
        $first = RestoreJob::factory()->create(['created_at' => now()->subDay()]);
        $later = RestoreJob::factory()->create();
        $this->mock(BackupWorkflow::class)->shouldReceive('run')->once()->with($first->id)
            ->andReturnUsing(function (string $id): never {
                RestoreJob::findOrFail($id)->update(['status' => 'rollback_failed']);
                throw new RuntimeException('PRIVATE_DATABASE_PASSWORD');
            });
        $this->artisan('backups:work')
            ->expectsOutput('Restore job '.$first->id.' failed; review its protected status before retrying.')
            ->doesntExpectOutput('PRIVATE_DATABASE_PASSWORD')->assertFailed();
        $this->assertSame('rollback_failed', $first->fresh()->status);
        $this->assertSame('queued', $later->fresh()->status);
    }
}
