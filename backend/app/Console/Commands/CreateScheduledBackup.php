<?php

namespace App\Console\Commands;

use App\Models\Backups\ServerBackup;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Backups\BackupWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('backups:create-scheduled')]
#[Description('Create a private signed encrypted backup when no valid backup exists in the past day')]
class CreateScheduledBackup extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupWorkflow $workflow, AuditLogger $audit): int
    {
        $lock = Cache::lock('masal.scheduled-backup', 3600);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            $latest = ServerBackup::where('status', 'completed')->where('created_at', '>', now()->subDay())->latest()->first();
            if ($latest && Storage::disk('local')->exists($latest->path) && hash_equals($latest->sha256, hash_file('sha256', Storage::disk('local')->path($latest->path)))) {
                $this->info('A verified backup already exists within the past day.');

                return self::SUCCESS;
            }
            $actor = User::where('status', 'active')->whereHas('membership', fn ($query) => $query->where('kind', 'owner')->where('status', 'active')->whereHas('account', fn ($account) => $account->where('type', 'system')->where('status', 'active')->whereNull('archived_at')))->firstOrFail();
            $backup = $workflow->create($actor);
            $request = Request::create('/internal/scheduled-backup', 'POST');
            $request->attributes->set('portal', 'admin');
            $audit->record('backup.create', $request, $actor, $actor->membership->account_id, ['backup_id' => $backup->id, 'sha256' => $backup->sha256, 'scheduled' => true]);
            $this->info('Scheduled backup completed: '.$backup->id);

            return self::SUCCESS;
        } catch (\Throwable $failure) {
            Log::error('Scheduled backup failed.', ['error_type' => get_class($failure)]);
            $this->error('Scheduled backup failed; review the protected backup status and server log.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
