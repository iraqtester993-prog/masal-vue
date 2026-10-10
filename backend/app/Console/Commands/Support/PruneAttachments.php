<?php

namespace App\Console\Commands\Support;

use App\Models\Support\SupportAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
                    $deleted = DB::transaction(function () use ($row): bool {
                        $image = SupportAttachment::where('assigned', false)->where('expires_at', '<=', now())->lockForUpdate()->find($row->id);
                        if (! $image) {
                            return false;
                        }
                        if (DB::table('support_tickets')->where('attachment_id', $image->id)->exists() || DB::table('notices')->where('attachment_id', $image->id)->exists()) {
                            return false;
                        }
                        if (! str_starts_with($image->path, 'support/attachments/') || ! Storage::disk('local')->delete($image->path)) {
                            throw new \RuntimeException('Unable to remove private attachment.');
                        }
                        $image->delete();

                        return true;
                    });
                    if ($deleted) {
                        $removed++;
                    }
                } catch (Throwable) {
                    $failed++;
                    $this->warn('Retry attachment #'.$row->id.' after checking local storage.');
                }
            }
        });
        $this->info('Removed '.$removed.' expired unassigned attachments.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
