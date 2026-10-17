<?php

namespace App\Console\Commands\Support;

use App\Models\Support\SupportAttachment;
use App\Services\Backups\BackupCoordination;
use App\Services\Operations\MutationGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PruneAttachments extends Command
{
    protected $signature = 'support:prune-attachments';

    protected $description = 'Remove expired unassigned private support and notification images.';

    public function handle(): int
    {
        $removed = 0;
        $failed = 0;
        SupportAttachment::where('assigned', false)->where('expires_at', '<=', now())->select('id')->chunkById(100, function ($rows) use (&$removed, &$failed): void {
            foreach ($rows as $row) {
                try {
                    $path = DB::transaction(function () use ($row): ?string {
                        app(MutationGuard::class)->lockRuntime();
                        $image = SupportAttachment::where('assigned', false)->where('expires_at', '<=', now())->lockForUpdate()->find($row->id);
                        if (! $image) {
                            return null;
                        }
                        if (DB::table('support_tickets')->where('attachment_id', $image->id)->exists() || DB::table('notices')->where('attachment_id', $image->id)->exists()) {
                            return null;
                        }
                        if (! str_starts_with($image->path, 'support/attachments/')) {
                            throw new \RuntimeException('Unable to remove private attachment.');
                        }
                        $image->delete();

                        return $image->path;
                    });
                    if ($path !== null) {
                        app(BackupCoordination::class)->deleteAsset($path);
                        $removed++;
                    }
                } catch (Throwable) {
                    $failed++;
                    $this->warn('Retry attachment #'.$row->id.' after checking local storage.');
                }
            }
        });
        app(BackupCoordination::class)->drainDeferredAssets();
        $this->info('Removed '.$removed.' expired unassigned attachments.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
