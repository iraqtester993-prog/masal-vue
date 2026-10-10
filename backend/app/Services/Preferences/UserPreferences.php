<?php

namespace App\Services\Preferences;

use App\Models\Preferences\UserPreference;
use App\Models\User;
use App\Services\Operations\MutationGuard;
use Illuminate\Support\Facades\DB;

class UserPreferences
{
    /** @return array{language: string, theme: string, version: int} */
    public function read(User $actor): array
    {
        abort_unless($actor->isOperational(), 403);
        $record = UserPreference::find($actor->id);

        return $record ? $record->only(['language', 'theme', 'version']) : ['language' => 'ar', 'theme' => 'light', 'version' => 0];
    }

    /** @return array{language: string, theme: string, version: int} */
    public function save(User $actor, array $data): array
    {
        return DB::transaction(function () use ($actor, $data): array {
            app(MutationGuard::class)->lock($actor, [], 'preferences.update');
            $record = UserPreference::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless((int) ($record?->version ?? 0) === (int) $data['version'], 409, 'تغيرت إعداداتك في جلسة أخرى؛ أعد تحميلها قبل الحفظ.');
            $record ??= new UserPreference;
            $record->forceFill(['user_id' => $actor->id, 'language' => $data['language'], 'theme' => $data['theme'], 'version' => (int) $data['version'] + 1])->save();

            return $record->only(['language', 'theme', 'version']);
        });
    }
}
