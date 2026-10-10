<?php

use App\Http\Controllers\Preferences\UserPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('preferences', [UserPreferenceController::class, 'index']);
Route::put('preferences', [UserPreferenceController::class, 'update']);
