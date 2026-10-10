<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SessionRevoker
{
    /** @param array<int> $userIds */
    public function revoke(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }
        DB::table('users')->whereIn('id', $userIds)->increment('session_version');
        DB::table('sessions')->whereIn('user_id', $userIds)->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
    }
}
