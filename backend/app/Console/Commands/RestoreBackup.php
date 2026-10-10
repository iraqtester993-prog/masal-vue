<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupWorkflow;
use Illuminate\Console\Command;

class RestoreBackup extends Command
{
    protected $signature = 'backups:restore {job : Reviewed restore job UUID}';

    protected $description = 'Restore a reviewed signed snapshot into a new verified database and switch through the configured platform';

    public function handle(BackupWorkflow $workflow): int
    {
        try {
            $job = $workflow->run($this->argument('job'));
            $this->info('Restore job '.$job->id.': '.$job->status);

            return self::SUCCESS;
        } catch (\Throwable $failure) {
            $this->error('Restore failed; inspect the protected job status and maintenance gate before allowing writes.');

            return self::FAILURE;
        }
    }
}
