<?php

namespace App\Services\Company;

use App\Models\Company\CompanyAsset;
use App\Models\Company\CompanyProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\MutationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyContent
{
    public const GALLERIES = ['slides', 'activities', 'offers', 'projects'];

    public function __construct(private CompanyAccess $access, private AuditLogger $audit) {}

    public function publicSnapshot(array $profile): array
    {
        foreach (self::GALLERIES as $key) {
            $profile[$key] = $profile['visibility'][$key] ? array_values(array_filter($profile[$key], fn (array $item): bool => $item['visible'])) : [];
        }
        if (! $profile['visibility']['about']) {
            $profile['about'] = '';
            $profile['website'] = '';
        }
        if (! $profile['visibility']['social']) {
            $profile['social'] = [];
        }
        if (! $profile['visibility']['care']) {
            foreach (['phone', 'email', 'address', 'whatsapp', 'hours'] as $key) {
                $profile[$key] = '';
            }
        }

        return $profile;
    }

    public function imageIds(array $profile): array
    {
        $ids = [$profile['logo'] ?? null];
        foreach (self::GALLERIES as $key) {
            foreach ($profile[$key] ?? [] as $item) {
                $ids[] = $item['image'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    public function transport(array $profile, bool $public): array
    {
        $convert = fn (?string $id): array|string|null => $id === null ? null : ($public ? '/api/v1/company/public/assets/'.$id : ['id' => $id, 'url' => '/api/v1/company/assets/'.$id]);
        $profile['logo'] = $convert($profile['logo'] ?? null);
        foreach (self::GALLERIES as $key) {
            $profile[$key] = array_map(function (array $item) use ($convert): array {
                $item['image'] = $convert($item['image'] ?? null);

                return $item;
            }, $profile[$key]);
        }

        return $profile;
    }

    public function dto(CompanyProfile $record): array
    {
        return ['profile' => $this->transport($record->content, false), 'version' => $record->version, 'published_at' => $record->published_at?->toISOString()];
    }

    public function save(User $actor, array $data, Request $request): array
    {
        $this->access->require($actor);

        return DB::transaction(function () use ($actor, $data, $request): array {
            app(MutationGuard::class)->lock($actor, [], 'company.edit');
            $record = CompanyProfile::lockForUpdate()->findOrFail(1);
            abort_unless($record->version === (int) $data['version'], 409, 'تغيرت إعدادات الشركة؛ أعد فتحها قبل الحفظ.');
            $profile = $data['profile'];
            foreach (['name', 'tagline', 'about', 'phone', 'email', 'address', 'website', 'whatsapp', 'hours'] as $key) {
                $profile[$key] = trim((string) ($profile[$key] ?? ''));
            }
            foreach (self::GALLERIES as $key) {
                $profile[$key] = array_map(fn (array $item): array => ['title' => trim($item['title']), 'caption' => trim($item['title']), 'description' => trim((string) ($item['description'] ?? '')), 'image' => $item['image'] ?? null, 'link' => trim((string) ($item['link'] ?? '')), 'visible' => (bool) $item['visible']], $profile[$key]);
            }
            $profile['social'] = array_map(fn (array $item): array => ['name' => trim($item['name']), 'url' => trim($item['url'])], $profile['social']);
            $profile['visibility'] = array_map(fn ($visible): bool => (bool) $visible, $profile['visibility']);
            $ids = $this->imageIds($profile);
            abort_unless(CompanyAsset::whereIn('id', $ids)->lockForUpdate()->count() === count($ids), 422, 'إحدى الصور غير متاحة.');
            $record->update(['content' => $profile, 'published' => $this->publicSnapshot($profile), 'version' => $record->version + 1, 'editor_id' => $actor->id, 'published_at' => now()]);
            $this->audit->record('company.update', $request, $actor, $actor->membership->account_id, ['version' => $record->version, 'sections' => $profile['visibility']]);

            return $this->dto($record);
        });
    }
}
