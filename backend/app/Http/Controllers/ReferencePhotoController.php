<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReferencePhotoRequest;
use App\Http\Resources\ReferenceResource;
use App\Models\RepresentativePhoto;
use App\Services\AuditLogger;
use App\Services\ManagementAuthority;
use App\Services\Operations\MutationGuard;
use App\Services\ReferenceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReferencePhotoController extends Controller
{
    public function store(ReferencePhotoRequest $request, int $id, ReferenceAccess $access, ManagementAuthority $authority, AuditLogger $audit): JsonResponse
    {
        $access->require($request->user(), 'representatives.images');
        $path = null;
        try {
            DB::transaction(function () use ($request, $id, $access, $authority, $audit, &$path): void {
                app(MutationGuard::class)->lock($request->user());
                $record = $access->representatives($request->user())->lockForUpdate()->findOrFail($id);
                $authority->version($record, $request->integer('version'));
                if (RepresentativePhoto::where('representative_id', $id)->count() >= 50) {
                    throw ValidationException::withMessages(['file' => 'الحد الأقصى 50 صورة لهذا المندوب.']);
                }
                $file = $request->file('file');
                $path = $file->store('representatives/'.$id.'/photos', 'local');
                abort_unless(is_string($path) && $path !== '', 503, 'تعذر حفظ الصورة.');
                $photo = RepresentativePhoto::create(['representative_id' => $id, 'storage_path' => $path, 'mime_type' => $file->getMimeType(), 'bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
                $record->version++;
                $record->save();
                $audit->record('representatives.photo.upload', $request, $request->user(), $record->agent_account_id, ['id' => $id, 'photo_id' => $photo->id, 'version' => $record->version]);
            });
        } catch (Throwable $error) {
            if (is_string($path)) {
                $this->remove($path, $id);
            }
            throw $error;
        }

        return response()->json(['data' => (new ReferenceResource($access->representatives($request->user())->with(['agent', 'photos'])->findOrFail($id)))->resolve($request)], 201);
    }

    public function destroy(ReferencePhotoRequest $request, int $id, int $photo, ReferenceAccess $access, ManagementAuthority $authority, AuditLogger $audit): ReferenceResource
    {
        $access->require($request->user(), 'representatives.images');
        $path = DB::transaction(function () use ($request, $id, $photo, $access, $authority, $audit): string {
            app(MutationGuard::class)->lock($request->user());
            $record = $access->representatives($request->user())->lockForUpdate()->findOrFail($id);
            $authority->version($record, $request->integer('version'));
            $image = RepresentativePhoto::where('representative_id', $id)->findOrFail($photo);
            $path = $image->storage_path;
            $image->delete();
            $record->version++;
            $record->save();
            $audit->record('representatives.photo.delete', $request, $request->user(), $record->agent_account_id, ['id' => $id, 'photo_id' => $photo, 'version' => $record->version, 'reason' => $request->input('reason')]);

            return $path;
        });
        $this->remove($path, $id);

        return new ReferenceResource($access->representatives($request->user())->with(['agent', 'photos'])->findOrFail($id));
    }

    public function content(Request $request, int $id, int $photo, ReferenceAccess $access): StreamedResponse
    {
        $access->require($request->user(), 'representatives.view');
        $access->representatives($request->user())->findOrFail($id);
        $image = RepresentativePhoto::where('representative_id', $id)->findOrFail($photo);
        $extension = match ($image->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => null,
        };
        abort_unless($extension !== null && Storage::disk('local')->exists($image->storage_path), 404);

        return Storage::disk('local')->response($image->storage_path, 'representative-photo-'.$image->id.'.'.$extension, ['Content-Type' => $image->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function remove(string $path, int $id): void
    {
        try {
            if (! Storage::disk('local')->delete($path)) {
                Log::warning('Representative photo cleanup requires retry.', ['representative_id' => $id]);
            }
        } catch (Throwable) {
            Log::warning('Representative photo cleanup requires retry.', ['representative_id' => $id]);
        }
    }
}
