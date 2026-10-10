<?php

namespace App\Services\Company;

use App\Models\Company\CompanyAsset;
use App\Models\Company\CompanyProfile;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CompanyAssets
{
    public function __construct(private CompanyAccess $access, private CompanyContent $content) {}

    public function upload(User $actor, UploadedFile $file): array
    {
        $this->access->require($actor);
        $ids = $this->content->imageIds(CompanyProfile::findOrFail(1)->content);
        abort_if(CompanyAsset::where('user_id', $actor->id)->whereNotIn('id', $ids)->where('created_at', '>', now()->subDay())->count() >= 20, 422, 'استخدم الصور المرفوعة قبل رفع المزيد.');
        $mime = $file->getMimeType();
        abort_unless(in_array($mime, ['image/png', 'image/jpeg'], true), 422, 'اختر صورة PNG أو JPEG.');
        $size = getimagesize($file->getRealPath());
        abort_unless($size && in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) && $size[0] <= 4096 && $size[1] <= 4096 && $file->getSize() <= 700000, 422, 'الصورة لا تطابق الحدود المسموحة.');
        $id = (string) Str::uuid();
        $path = null;
        try {
            $record = DB::transaction(function () use ($actor, $file, $id, $mime, $size, &$path): CompanyAsset {
                app(MutationGuard::class)->lock($actor, [], 'company.edit');
                $ids = $this->content->imageIds(CompanyProfile::findOrFail(1)->content);
                abort_if(CompanyAsset::where('user_id', $actor->id)->whereNotIn('id', $ids)->where('created_at', '>', now()->subDay())->count() >= 20, 422, 'استخدم الصور المرفوعة قبل رفع المزيد.');
                $path = $file->storeAs('company/assets', $id.($mime === 'image/png' ? '.png' : '.jpg'), 'local');
                abort_unless(is_string($path) && $path !== '', 503, 'تعذر حفظ الصورة.');

                return CompanyAsset::create(['id' => $id, 'user_id' => $actor->id, 'path' => $path, 'mime' => $mime, 'bytes' => $file->getSize(), 'width' => $size[0], 'height' => $size[1]]);
            });
        } catch (Throwable $error) {
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            } throw $error;
        }

        return ['id' => $record->id, 'url' => '/api/v1/company/assets/'.$record->id, 'bytes' => $record->bytes, 'width' => $record->width, 'height' => $record->height];
    }

    public function response(string $id, ?User $actor = null): StreamedResponse
    {
        if ($actor !== null) {
            $this->access->require($actor);
        } else {
            $published = CompanyProfile::findOrFail(1)->published;
            abort_unless($published !== null && in_array($id, $this->content->imageIds($published), true), 404);
        }
        $image = CompanyAsset::findOrFail($id);
        abort_unless(Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, 'company-'.$id.($image->mime === 'image/png' ? '.png' : '.jpg'), ['Content-Type' => $image->mime, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
