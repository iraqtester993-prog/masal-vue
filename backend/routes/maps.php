<?php

use App\Http\Controllers\Maps\MapController;
use Illuminate\Support\Facades\Route;

Route::prefix('maps')->group(function (): void {
    Route::get('users', [MapController::class, 'index']);
    Route::get('own', [MapController::class, 'own']);
    Route::post('heartbeat', [MapController::class, 'heartbeat'])->middleware('throttle:10,1');
    Route::post('location', [MapController::class, 'locate'])->middleware('throttle:10,1');
    Route::post('disconnect', [MapController::class, 'disconnect']);
    Route::put('accounts/{id}/location', [MapController::class, 'saveLocation'])->whereNumber('id');
});
