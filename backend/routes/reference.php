<?php

use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\ReferencePhotoController;
use Illuminate\Support\Facades\Route;

Route::get('reference/options', [ReferenceController::class, 'options']);
Route::get('reference/{kind}/export', [ReferenceController::class, 'export'])->whereIn('kind', ['pos-types', 'representatives']);
Route::get('reference/{kind}', [ReferenceController::class, 'index'])->whereIn('kind', ['governorates', 'sources', 'pos-types', 'representatives']);
Route::post('reference/{kind}', [ReferenceController::class, 'store'])->whereIn('kind', ['sources', 'pos-types', 'representatives']);
Route::patch('reference/{kind}/{id}', [ReferenceController::class, 'update'])->whereIn('kind', ['sources', 'pos-types', 'representatives'])->whereNumber('id');
Route::patch('reference/{kind}/{id}/status', [ReferenceController::class, 'status'])->whereIn('kind', ['governorates', 'sources', 'pos-types', 'representatives'])->whereNumber('id');
Route::post('reference/representatives/{id}/photos', [ReferencePhotoController::class, 'store'])->whereNumber('id');
Route::delete('reference/representatives/{id}/photos/{photo}', [ReferencePhotoController::class, 'destroy'])->whereNumber(['id', 'photo']);
Route::get('reference/representatives/{id}/photos/{photo}/content', [ReferencePhotoController::class, 'content'])->whereNumber(['id', 'photo']);
Route::get('accounts/{id}/reference-profile', [ReferenceController::class, 'profile'])->whereNumber('id');
Route::put('accounts/{id}/reference-profile', [ReferenceController::class, 'saveProfile'])->whereNumber('id');
