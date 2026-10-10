<?php

namespace App\Console\Commands;

use App\Models\Backups\RestoreJob;
use App\Services\Backups\BackupWorkflow;
use Illuminate\Console\Command;

class WorkBackups extends Command
{
    protected $signature = 'backups:work';

    protected $description = 'Process one reviewed queued restore job with a private exclusive worker lock';

    public function handle(BackupWorkflow $workflow): int
    {
        $path = storage_path('framework/backup-worker.lock');
        $lock = fopen($path, 'c');
        if ($lock === false) {
            $this->error('Private restore worker lock is unavailable.');

            return self::FAILURE;
        }
        chmod($path, 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return self::SUCCESS;
        }
        try {
            $job = RestoreJob::where('status', 'queued')->orderBy('created_at')->first();
            if ($job === null) {
                return self::SUCCESS;
            }
            try {
                $workflow->run($job->id);
                $this->info('Restore job '.$job->id.': completed.');

                return self::SUCCESS;
            } catch (\Throwable) {
                $this->error('Restore job '.$job->id.' failed; review its protected status before retrying.');

                return self::FAILURE;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
