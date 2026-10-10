<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogExportController;
use App\Http\Controllers\PermissionProfileController;
use App\Http\Controllers\PhoneRecoveryController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    if (is_file(__DIR__.'/company.php')) {
        require __DIR__.'/company.php';
    }
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/recovery/phone', [PhoneRecoveryController::class, 'issue'])->middleware('throttle:5,1');
    Route::post('auth/recovery/reset', [PhoneRecoveryController::class, 'reset'])->middleware('throttle:15,1');
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'portal.member:session']);
    Route::middleware(['auth:sanctum', 'portal.member'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::patch('auth/profile', [AuthController::class, 'profile']);
        Route::get('accounts', [AccountController::class, 'index']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('accounts/{id}', [AccountController::class, 'show'])->whereNumber('id');
        Route::get('account-options', [AccountManagementController::class, 'options']);
        Route::get('permission-catalog', [AccountManagementController::class, 'catalog']);
        Route::patch('accounts/{id}', [AccountManagementController::class, 'update'])->whereNumber('id');
        foreach (['status', 'permissions', 'login'] as $action) {
            Route::patch('accounts/{id}/'.$action, [AccountManagementController::class, $action])->whereNumber('id');
        }
        Route::get('accounts/{id}/staff', [StaffController::class, 'index'])->whereNumber('id');
        Route::post('accounts/{id}/staff', [StaffController::class, 'store'])->whereNumber('id');
        Route::patch('accounts/{id}/staff/{memberId}', [StaffController::class, 'update'])->whereNumber(['id', 'memberId']);
        Route::patch('accounts/{id}/staff/{memberId}/status', [StaffController::class, 'status'])->whereNumber(['id', 'memberId']);
        Route::get('accounts/{id}/permission-profiles', [PermissionProfileController::class, 'index'])->whereNumber('id');
        Route::post('accounts/{id}/permission-profiles', [PermissionProfileController::class, 'store'])->whereNumber('id');
        Route::patch('accounts/{id}/permission-profiles/{profileId}', [PermissionProfileController::class, 'update'])->whereNumber(['id', 'profileId']);
        Route::patch('accounts/{id}/permission-profiles/{profileId}/status', [PermissionProfileController::class, 'status'])->whereNumber(['id', 'profileId']);
        Route::delete('accounts/{id}/permission-profiles/{profileId}', [PermissionProfileController::class, 'destroy'])->whereNumber(['id', 'profileId']);
        require __DIR__.'/attachments.php';
        foreach (['reference', 'finance', 'stock', 'stock-read', 'sales', 'support', 'reports', 'operations', 'digital', 'maps', 'backups', 'preferences'] as $module) {
            if (is_file(__DIR__.'/'.$module.'.php')) {
                require __DIR__.'/'.$module.'.php';
            }
        }
        $catalog = CatalogController::class;
        Route::get('catalog/options', [$catalog, 'options']);
        Route::get('catalog/preferences', [$catalog, 'preferences']);
        Route::put('catalog/preferences', [$catalog, 'savePreferences']);
        Route::get('catalog/{kind}/export', CatalogExportController::class)->whereIn('kind', ['products', 'providers']);
        foreach (['products' => 'Product', 'providers' => 'Provider'] as $path => $suffix) {
            Route::get('catalog/'.$path, [$catalog, $path]);
            Route::post('catalog/'.$path, [$catalog, 'store'.$suffix]);
            Route::patch('catalog/'.$path.'/{id}', [$catalog, 'update'.$suffix])->whereNumber('id');
            Route::patch('catalog/'.$path.'/{id}/status', [$catalog, lcfirst($suffix).'Status'])->whereNumber('id');
            Route::get('catalog/'.$path.'/{id}/image', [$catalog, lcfirst($suffix).'Image'])->whereNumber('id');
        }
        Route::patch('catalog/products/{id}/move', [$catalog, 'moveProduct'])->whereNumber('id');
        Route::get('accounts/{id}/categories', [$catalog, 'categories'])->whereNumber('id');
        Route::put('accounts/{id}/categories', [$catalog, 'saveCategories'])->whereNumber('id');
    });
});
