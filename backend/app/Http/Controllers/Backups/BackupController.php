<?php

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backups\BackupRequest;
use App\Http\Resources\Backups\BackupResource;
use App\Models\Backups\RestoreJob;
use App\Models\Backups\ServerBackup;
use App\Services\AuditLogger;
use App\Services\Backups\BackupAccess;
use App\Services\Backups\BackupWorkflow;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BackupController extends Controller
{
    public function __construct(private BackupAccess $access, private BackupWorkflow $workflow, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->require($request->user(), 'backup.view');
        $jobs = in_array('backup.restore', $request->user()->membership->permissions(), true) ? RestoreJob::latest()->limit(10)->get() : collect();

        return response()->json(['data' => BackupResource::collection(ServerBackup::latest()->limit(30)->get()), 'restore_jobs' => BackupResource::collection($jobs), 'capabilities' => $this->workflow->capabilities()]);
    }

    public function create(BackupRequest $request): BackupResource
    {
        try {
            $backup = DB::transaction(function () use ($request): ServerBackup|\Throwable {
                app(MutationGuard::class)->lock($request->user(), [], 'backup.create');
                try {
                    $backup = $this->workflow->create($request->user());
                    $this->audit->record('backup.create', $request, $request->user(), $request->user()->membership->account_id, ['backup_id' => $backup->id, 'sha256' => $backup->sha256]);

                    return $backup;
                } catch (\Throwable $failure) {
                    return $failure;
                }
            });
            if ($backup instanceof \Throwable) {
                throw $backup;
            }

            return new BackupResource($backup);
        } catch (\Throwable $failure) {
            if ($failure instanceof HttpExceptionInterface) {
                throw $failure;
            }
            abort(503, get_class($failure) === RuntimeException::class ? $failure->getMessage() : 'تعذر إنشاء النسخة الاحتياطية.');
        }
    }

    public function download(Request $request, string $id): BinaryFileResponse
    {
        $this->access->require($request->user(), 'backup.view');
        $backup = ServerBackup::where('status', 'completed')->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($backup->path) && hash_equals($backup->sha256, hash_file('sha256', Storage::disk('local')->path($backup->path))), 422, 'بصمة النسخة لا تطابق ملفها.');

        return response()->download(Storage::disk('local')->path($backup->path), 'masal-laravel-backup-'.$id.'.json', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preview(BackupRequest $request): BackupResource
    {
        try {
            return new BackupResource(DB::transaction(function () use ($request) {
                app(MutationGuard::class)->lock($request->user(), [], 'backup.restore');

                return $this->workflow->preview($request->user(), $request->file('file'), $request->validated('backup_id'));
            }));
        } catch (\Throwable $failure) {
            if ($failure instanceof HttpExceptionInterface) {
                throw $failure;
            }
            abort(422, get_class($failure) === RuntimeException::class ? $failure->getMessage() : 'فشل فحص علاقات النسخة أو قيودها؛ لم تتغير بيانات النظام.');
        }
    }

    public function restore(BackupRequest $request): BackupResource
    {
        $job = DB::transaction(function () use ($request): RestoreJob {
            $job = $this->workflow->queue($request->user(), $request->validated());
            $this->audit->record('backup.restore-reviewed', $request, $request->user(), $request->user()->membership->account_id, ['restore_job_id' => $job->id, 'preview_id' => $job->preview_id]);

            return $job;
        });

        return new BackupResource($job);
    }

    public function job(Request $request, string $id): BackupResource
    {
        $this->access->require($request->user(), 'backup.restore');

        return new BackupResource(RestoreJob::findOrFail($id));
    }
}
