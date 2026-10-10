<?php

namespace App\Services\Backups;

use App\Contracts\Backups\RestorePlatform;
use App\Models\Backups\BackupPreview;
use App\Models\Backups\RestoreJob;
use App\Models\Backups\ServerBackup;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use Illuminate\Database\Connection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BackupWorkflow
{
    public function __construct(private BackupAccess $access, private SnapshotArchive $archive, private SnapshotImporter $importer) {}

    public function platform(): RestorePlatform
    {
        $platform = app(config('backups.platform_class'));
        if (! $platform instanceof RestorePlatform) {
            throw new RuntimeException('منصة الاسترجاع لا تطابق العقد.');
        }

        return $platform;
    }

    public function capabilities(): array
    {
        return $this->platform()->capabilities() + ['upload_bytes' => config('backups.upload_bytes'), 'reference_validation' => extension_loaded('pdo_sqlite')];
    }

    public function create(User $actor, ?string $connection = null): ServerBackup
    {
        $this->access->require($actor, 'backup.create');
        $db = DB::connection($connection);
        $backup = ServerBackup::on($db->getName())->create(['id' => (string) Str::uuid(), 'creator_id' => $actor->id, 'status' => 'creating']);
        $path = 'backups/snapshots/'.$backup->id.'.json';
        try {
            $data = $this->archive->create($db, $path);
            $backup->update($data + ['path' => $path, 'status' => 'completed']);

            return $backup;
        } catch (\Throwable $failure) {
            $backup->update(['status' => 'failed', 'failure' => get_class($failure) === RuntimeException::class ? mb_substr($failure->getMessage(), 0, 300) : 'تعذر نسخ بيانات النظام أو مرفقاته.']);
            throw $failure;
        }
    }

    public function preview(User $actor, ?UploadedFile $file, ?string $backupId): BackupPreview
    {
        $this->access->require($actor, 'backup.restore');
        $id = (string) Str::uuid();
        $backup = null;
        if ($file !== null) {
            if ($file->getSize() > config('backups.upload_bytes')) {
                abort(422, 'الرفع من الحاسبة محدود بـ15 ميغابايت؛ اختر نسخة السيرفر للأحجام الأكبر.');
            }
            $path = $file->storeAs('backups/previews', $id.'.json', 'local');
            abort_unless(is_string($path), 503, 'تعذر حفظ نسخة الفحص.');
            @chmod(Storage::disk('local')->path($path), 0600);
        } else {
            $backup = ServerBackup::where('status', 'completed')->findOrFail($backupId);
            $path = $backup->path;
            abort_unless(Storage::disk('local')->exists($path) && hash_equals($backup->sha256, hash_file('sha256', Storage::disk('local')->path($path))), 422, 'بصمة النسخة المحفوظة لا تطابق ملفها.');
        }
        try {
            $verification = $this->importer->preview($path, DB::connection());

            return BackupPreview::create(['id' => $id, 'creator_id' => $actor->id, 'backup_id' => $backup?->id, 'path' => $path, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'manifest' => $verification['manifest'], 'reference_verified' => $verification['reference_verified'], 'expires_at' => now()->addHours(config('backups.preview_hours'))])->refresh();
        } catch (\Throwable $failure) {
            if ($file !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $failure;
        }
    }

    public function queue(User $actor, array $data): RestoreJob
    {
        $this->access->require($actor, 'backup.restore');
        $this->access->require($actor, 'backup.create');
        abort_unless(Hash::check($data['current_password'], $actor->password), 422, 'كلمة المرور الحالية غير صحيحة.');
        $capabilities = $this->capabilities();
        abort_unless($capabilities['prepare'] && $capabilities['switch'], 409, $capabilities['reason'] ?? 'الاسترجاع غير متاح قبل إكمال ربط الاستضافة.');

        return DB::transaction(function () use ($actor, $data): RestoreJob {
            app(MutationGuard::class)->lock($actor, [], 'backup.restore');
            $this->access->require($actor, 'backup.create');
            abort_unless(Hash::check($data['current_password'], $actor->password), 422, 'كلمة المرور الحالية غير صحيحة.');
            $preview = BackupPreview::where('creator_id', $actor->id)->lockForUpdate()->findOrFail($data['preview_id']);
            abort_unless($preview->version === $data['version'] && $preview->expires_at->isFuture() && $preview->reference_verified, 409, 'المعاينة منتهية أو لم يكتمل فحص العلاقات.');
            abort_if(RestoreJob::whereIn('status', ['queued', 'preparing', 'validated', 'switching'])->lockForUpdate()->exists(), 409, 'توجد عملية استرجاع قيد التنفيذ.');
            $preview->increment('version');

            return RestoreJob::create(['id' => (string) Str::uuid(), 'creator_id' => $actor->id, 'preview_id' => $preview->id, 'status' => 'queued', 'reviewed_at' => now()])->refresh();
        });
    }

    public function run(string $id): RestoreJob
    {
        if (app()->runningInConsole() === false) {
            throw new RuntimeException('الاسترجاع يُنفذ من عامل الأوامر فقط.');
        }
        $initialSource = DB::connection();
        $sourceName = $initialSource->getDriverName() === 'sqlite' && $initialSource->getDatabaseName() === ':memory:' ? $initialSource->getName() : 'backup_source_'.$id;
        if ($sourceName !== $initialSource->getName()) {
            config(['database.connections.'.$sourceName => ['name' => $sourceName] + $initialSource->getConfig()]);
        }
        $source = DB::connection($sourceName);
        $job = DB::transaction(function () use ($id): RestoreJob {
            $job = RestoreJob::lockForUpdate()->findOrFail($id);
            abort_unless($job->status === 'queued', 409, 'العملية ليست بانتظار التنفيذ.');
            $job->update(['status' => 'preparing', 'version' => $job->version + 1]);

            return $job;
        });
        $job->setConnection($source->getName());
        $preview = BackupPreview::on($source->getName())->findOrFail($job->preview_id);
        $actor = User::on($source->getName())->findOrFail($job->creator_id);
        $scratchName = 'backup_restore_'.$job->id;
        $assetFolder = 'backups/restore/'.$job->id.'/assets';
        $switch = null;
        $maintenance = null;
        try {
            $this->access->require($actor, 'backup.restore');
            abort_unless($preview->reference_verified && $preview->expires_at->isFuture(), 409, 'انتهت صلاحية المعاينة.');
            abort_unless(hash_equals($preview->sha256, hash_file('sha256', Storage::disk('local')->path($preview->path))), 422, 'تغير ملف النسخة بعد المعاينة.');
            $caps = $this->platform()->capabilities();
            abort_unless($caps['prepare'] && $caps['switch'], 409, $caps['reason'] ?? 'منصة الاسترجاع غير متاحة.');
            $scratch = $this->platform()->createScratch($job->id);
            $job->update(['scratch' => $scratch, 'version' => $job->version + 1]);
            config(['database.connections.'.$scratchName => $scratch['connection']]);
            $this->importer->restore($preview->path, $scratchName, $source, $assetFolder);
            $job->update(['status' => 'validated', 'version' => $job->version + 1]);
            $maintenance = $this->platform()->beginMaintenance($job->id);
            $job->update(['rollback' => $maintenance['rollback'], 'version' => $job->version + 1]);
            $safety = $this->create($actor, $source->getName());
            $job->update(['safety_backup_id' => $safety->id, 'status' => 'switching', 'version' => $job->version + 1]);
            $this->promoteAssets($assetFolder);
            $this->copyControlRecords($source, DB::connection($scratchName));
            $switch = $this->platform()->switchVerified($job->id, $scratch);
            $this->platform()->finishMaintenance($job->id);
            $job->update(['rollback' => $switch['rollback'], 'status' => 'completed', 'completed_at' => now(), 'version' => $job->version + 1]);
            DB::connection($scratchName)->table('restore_jobs')->where('id', $job->id)->update(['status' => 'completed', 'completed_at' => now(), 'version' => $job->version, 'rollback' => $job->getRawOriginal('rollback')]);
            $job->setConnection($initialSource->getName());

            return $job;
        } catch (\Throwable $failure) {
            if ($switch !== null || $maintenance !== null) {
                try {
                    $this->platform()->rollbackSwitch(($switch ?? $maintenance)['rollback']);
                } catch (\Throwable $rollbackFailure) {
                    $job->update(['status' => 'rollback_failed', 'failure' => 'فشل الرجوع الآلي؛ أبقِ النظام في الصيانة حتى مراجعة القاعدتين وملف البيئة.', 'version' => $job->version + 1]);
                    throw new RuntimeException('فشل الرجوع الآلي؛ لا تفتح النظام قبل مراجعة حالة الاسترجاع.', previous: $rollbackFailure);
                }
            }
            $job->update(['status' => 'failed', 'failure' => get_class($failure) === RuntimeException::class ? mb_substr($failure->getMessage(), 0, 300) : 'فشل الفحص أو التحويل؛ لم يكتمل الاسترجاع.', 'version' => $job->version + 1]);
            throw $failure;
        } finally {
            DB::purge($scratchName);
            config(['database.connections.'.$scratchName => null]);
            if ($sourceName !== $initialSource->getName()) {
                DB::purge($sourceName);
                config(['database.connections.'.$sourceName => null]);
            }
            Storage::disk('local')->deleteDirectory('backups/restore/'.$job->id);
        }
    }

    private function promoteAssets(string $folder): void
    {
        $disk = Storage::disk('local');
        $root = $disk->path($folder);
        if (! is_dir($root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->isLink()) {
                throw new RuntimeException('مرفق الاسترجاع غير صالح.');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            app(SnapshotSchema::class)->safeAsset($relative);
            if ($disk->exists($relative)) {
                if (! hash_equals(hash_file('sha256', $file->getPathname()), hash_file('sha256', $disk->path($relative)))) {
                    throw new RuntimeException('مرفق النسخة يتعارض مع ملف النظام الحالي.');
                }

                continue;
            }
            $disk->makeDirectory(dirname($relative));
            if (! copy($file->getPathname(), $disk->path($relative))) {
                throw new RuntimeException('تعذر حفظ مرفق الاسترجاع.');
            }
            @chmod($disk->path($relative), 0600);
        }
    }

    private function copyControlRecords(Connection $source, Connection $destination): void
    {
        foreach (['server_backups', 'backup_previews', 'restore_jobs'] as $table) {
            foreach (app(SnapshotSchema::class)->rows($source, $table, ['id']) as $row) {
                if ($row['creator_id'] !== null && ! $destination->table('users')->where('id', $row['creator_id'])->exists()) {
                    $row['creator_id'] = null;
                }
                $destination->table($table)->insert($row);
            }
        }
    }
}
