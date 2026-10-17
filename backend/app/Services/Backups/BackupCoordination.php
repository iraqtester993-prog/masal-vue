<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BackupCoordination
{
    /** @var array<string,array{handle:resource,exclusive:bool}> */
    private static array $held = [];

    public function lockPath(): string
    {
        return config('backups.coordination_path', storage_path('framework/backup-coordination.lock'));
    }

    public function read(callable $action): mixed
    {
        try {
            return $this->synchronized(false, $action);
        } finally {
            $this->drainDeferredAssets();
        }
    }

    public function restore(callable $action): mixed
    {
        return $this->synchronized(true, $action);
    }

    /** Shared storage, not a business-account row, coordinates backup/restore workers. */
    private function synchronized(bool $exclusive, callable $action): mixed
    {
        $path = $this->lockPath();
        if (isset(self::$held[$path])) {
            abort_if($exclusive && ! self::$held[$path]['exclusive'], 409, 'توجد نسخة احتياطية أو معاينة قيد التنفيذ؛ أعد المحاولة بعد اكتمالها.');

            return $action();
        }
        if (is_link($path)) {
            throw new RuntimeException('ملف تنسيق النسخ غير صالح.');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('تعذر فتح قفل تنسيق النسخ.');
        }
        try {
            @chmod($path, 0600);
            abort_unless(flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB), 409, 'توجد عملية نسخ أو استرجاع أخرى؛ أعد المحاولة بعد اكتمالها.');
            self::$held[$path] = ['handle' => $handle, 'exclusive' => $exclusive];

            return $action();
        } finally {
            unset(self::$held[$path]);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Asset names are immutable. Deletion waits without retaining any database locks. */
    public function deleteAsset(string $path): bool
    {
        app(SnapshotSchema::class)->safeAsset($path);
        $disk = Storage::disk('local');
        $disk->makeDirectory('backups/deferred-assets');
        $entry = 'backups/deferred-assets/'.hash('sha256', $path).'.json';
        if (! $disk->put($entry, json_encode(['path' => $path], JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('تعذر تسجيل تنظيف المرفق بصورة محفوظة.');
        }
        $this->drainDeferredAssets();

        return true;
    }

    public function drainDeferredAssets(): void
    {
        $disk = Storage::disk('local');
        $path = $this->lockPath();
        if (isset(self::$held[$path]) || ! is_dir($disk->path('backups/deferred-assets')) || is_link($path)) {
            return;
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }
            if (DB::getSchemaBuilder()->hasTable('runtime_write_gate') && DB::table('runtime_write_gate')->where('id', 1)->where('blocked', true)->exists()) {
                return;
            }
            $count = 0;
            foreach (new \FilesystemIterator($disk->path('backups/deferred-assets'), \FilesystemIterator::SKIP_DOTS) as $file) {
                if (++$count > 1000) {
                    break;
                }
                try {
                    if (! $file->isFile() || $file->isLink() || $file->getSize() > 1024 || ! preg_match('/^[a-f0-9]{64}\.json$/D', $file->getFilename())) {
                        continue;
                    }
                    $entry = json_decode(file_get_contents($file->getPathname()), true, 8, JSON_THROW_ON_ERROR);
                    $asset = $entry['path'] ?? '';
                    app(SnapshotSchema::class)->safeAsset($asset);
                    if (hash('sha256', $asset).'.json' !== $file->getFilename()) {
                        continue;
                    }
                    if (! $this->referenced($asset) && ! $disk->delete($asset)) {
                        continue;
                    }
                    $disk->delete('backups/deferred-assets/'.$file->getFilename());
                } catch (\Throwable) {
                    Log::warning('Deferred backup asset cleanup requires retry.');
                }
            }
        } catch (\Throwable) {
            Log::warning('Deferred backup asset cleanup requires retry.');
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function referenced(string $path): bool
    {
        foreach (['company_assets' => 'path', 'support_attachments' => 'path', 'account_attachments' => 'storage_path', 'representative_photos' => 'storage_path', 'catalog_products' => 'image_path', 'catalog_providers' => 'image_path'] as $table => $column) {
            if (DB::table($table)->where($column, $path)->exists()) {
                return true;
            }
        }

        return DB::table('sales_receipt_layouts')->where('layout->agent_image_path', $path)->exists();
    }
}
