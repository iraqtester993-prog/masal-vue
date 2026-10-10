<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountAttachmentRequest;
use App\Http\Resources\AccountAttachmentResource;
use App\Models\Account;
use App\Models\AccountAttachment;
use App\Services\AccountScope;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AccountAttachmentController extends Controller
{
    public function index(Request $request, int $id, AccountScope $scope): JsonResponse
    {
        $account = $scope->query($request->user())->findOrFail($id);
        Gate::authorize('viewAttachments', $account);

        return response()->json([
            'data' => AccountAttachmentResource::collection(AccountAttachment::where('account_id', $id)->orderBy('id')->get())->resolve($request),
            'account_version' => (int) $account->version,
        ]);
    }

    public function store(StoreAccountAttachmentRequest $request, int $id, AccountScope $scope, AuditLogger $audit): JsonResponse
    {
        $attributes = $request->validated();
        $newPath = null;
        $replacedPaths = [];
        try {
            [$attachment, $version] = DB::transaction(function () use ($request, $id, $scope, $audit, $attributes, &$newPath, &$replacedPaths): array {
                app(MutationGuard::class)->lock($request->user());
                $actor = $request->user()->fresh();
                $account = $scope->query($actor)->lockForUpdate()->findOrFail($id);
                Gate::forUser($actor)->authorize('manageAttachments', $account);
                $this->checkVersion($account, (int) $attributes['version']);
                if ($attributes['kind'] !== 'document') {
                    $existing = AccountAttachment::where('account_id', $id)->where('kind', $attributes['kind'])->get();
                    $replacedPaths = $existing->pluck('storage_path')->all();
                    foreach ($existing as $old) {
                        $old->delete();
                    }
                }
                if (AccountAttachment::where('account_id', $id)->count() >= 50) {
                    throw ValidationException::withMessages(['file' => 'الحد الأقصى 50 صورة لهذا الحساب.']);
                }
                $file = $attributes['file'];
                $newPath = $file->store('accounts/'.$id.'/attachments', 'local');
                abort_unless(is_string($newPath) && $newPath !== '', 503, 'تعذر حفظ الصورة.');
                $attachment = AccountAttachment::create([
                    'account_id' => $id,
                    'kind' => $attributes['kind'],
                    'document_type' => $attributes['kind'] === 'document' ? $attributes['document_type'] : null,
                    'storage_path' => $newPath,
                    'mime_type' => $file->getMimeType(),
                    'bytes' => $file->getSize(),
                    'uploaded_by' => $actor->id,
                ]);
                $account->increment('version');
                $audit->record('account.attachment.upload', $request, $actor, $id, ['attachment_id' => $attachment->id, 'kind' => $attachment->kind]);

                return [$attachment, (int) $account->version];
            });
        } catch (Throwable $error) {
            if (is_string($newPath)) {
                $this->removePrivateFiles([$newPath], $id);
            }
            throw $error;
        }
        $this->removePrivateFiles($replacedPaths, $id);

        return response()->json(['data' => (new AccountAttachmentResource($attachment))->resolve($request), 'account_version' => $version], 201);
    }

    public function content(Request $request, int $id, int $attachment, AccountScope $scope): StreamedResponse
    {
        $account = $scope->query($request->user())->findOrFail($id);
        Gate::authorize('viewAttachments', $account);
        $record = AccountAttachment::where('account_id', $id)->findOrFail($attachment);
        $extension = match ($record->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => null,
        };
        abort_unless($extension !== null && Storage::disk('local')->exists($record->storage_path), 404);

        return Storage::disk('local')->response($record->storage_path, 'photo-'.$record->id.'.'.$extension, [
            'Content-Type' => $record->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, int $id, int $attachment, AccountScope $scope, AuditLogger $audit): JsonResponse
    {
        $account = $scope->query($request->user())->findOrFail($id);
        Gate::authorize('manageAttachments', $account);
        $attributes = $request->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:3', 'max:2000']]);
        foreach (array_diff(array_keys($request->except('_token')), ['version', 'reason']) as $field) {
            throw ValidationException::withMessages([$field => 'هذا الحقل غير مسموح.']);
        }
        [$path, $version] = DB::transaction(function () use ($request, $id, $attachment, $scope, $audit, $attributes): array {
            app(MutationGuard::class)->lock($request->user());
            $actor = $request->user()->fresh();
            $account = $scope->query($actor)->lockForUpdate()->findOrFail($id);
            Gate::forUser($actor)->authorize('manageAttachments', $account);
            $this->checkVersion($account, (int) $attributes['version']);
            $record = AccountAttachment::where('account_id', $id)->findOrFail($attachment);
            $path = $record->storage_path;
            $record->delete();
            $account->increment('version');
            $audit->record('account.attachment.delete', $request, $actor, $id, ['attachment_id' => $attachment, 'reason' => $attributes['reason']]);

            return [$path, (int) $account->version];
        });
        $this->removePrivateFiles([$path], $id);

        return response()->json(['account_version' => $version]);
    }

    private function checkVersion(Account $account, int $version): void
    {
        abort_if((int) $account->version !== $version, 409, 'تغير الحساب. حدّث التفاصيل ثم أعد المحاولة.');
    }

    private function removePrivateFiles(array $paths, int $accountId): void
    {
        if ($paths === []) {
            return;
        }
        try {
            if (! Storage::disk('local')->delete($paths)) {
                Log::warning('Account attachment file cleanup requires retry.', ['account_id' => $accountId]);
            }
        } catch (Throwable) {
            Log::warning('Account attachment file cleanup requires retry.', ['account_id' => $accountId]);
        }
    }
}
