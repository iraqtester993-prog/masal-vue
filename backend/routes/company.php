<?php

use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Company\CompanyPublicController;
use Illuminate\Support\Facades\Route;

Route::prefix('company')->group(function (): void {
    Route::get('public', [CompanyPublicController::class, 'profile'])->middleware('throttle:60,1,company-public:');
    Route::get('public/assets/{id}', [CompanyPublicController::class, 'asset'])->whereUuid('id')->middleware('throttle:120,1,company-public-assets:');
    Route::post('inquiries/track', [CompanyPublicController::class, 'track'])->middleware('throttle:30,1,company-tracking:');
    Route::post('inquiries/followup', [CompanyPublicController::class, 'followup'])->middleware('throttle:5,1,company-followup:');
    Route::post('inquiries', [CompanyPublicController::class, 'inquiry'])->middleware('throttle:5,1,company-inquiries:');
    Route::middleware(['auth:sanctum', 'portal.member'])->group(function (): void {
        Route::get('profile', [CompanyController::class, 'profile']);
        Route::put('profile', [CompanyController::class, 'save']);
        Route::post('assets', [CompanyController::class, 'upload'])->middleware('throttle:20,1,company-upload:');
        Route::get('assets/{id}', [CompanyController::class, 'asset'])->whereUuid('id');
        Route::get('inquiries', [CompanyController::class, 'inquiries']);
        Route::patch('inquiries/{id}', [CompanyController::class, 'review'])->whereNumber('id');
    });
});
