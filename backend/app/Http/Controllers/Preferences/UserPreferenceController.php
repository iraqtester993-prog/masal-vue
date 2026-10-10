<?php

namespace App\Http\Controllers\Preferences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Preferences\UserPreferenceRequest;
use App\Services\AuditLogger;
use App\Services\Preferences\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserPreferenceController extends Controller
{
    public function index(UserPreferenceRequest $request, UserPreferences $preferences): JsonResponse
    {
        return response()->json(['data' => $preferences->read($request->user())]);
    }

    public function update(UserPreferenceRequest $request, UserPreferences $preferences, AuditLogger $audit): JsonResponse
    {
        $data = DB::transaction(function () use ($request, $preferences, $audit): array {
            $result = $preferences->save($request->user(), $request->validated());
            $audit->record('preferences.updated', $request, $request->user(), null, $result);

            return $result;
        });

        return response()->json(['data' => $data]);
    }
}
