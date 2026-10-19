<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountLookupController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogExportController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PermissionProfileController;
use App\Http\Controllers\PhoneRecoveryController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function () {
    if (is_file(__DIR__.'/company.php')) {
        require __DIR__.'/company.php';
    }
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/device-challenge', [AuthController::class, 'deviceChallenge'])->middleware('throttle:device-login-challenge');
    Route::post('auth/recovery/phone', [PhoneRecoveryController::class, 'issue'])->middleware('throttle:5,1,phone-recovery-issue:');
    Route::post('auth/recovery/reset', [PhoneRecoveryController::class, 'reset'])->middleware('throttle:15,1,phone-recovery-complete:');
    Route::post('auth/password-reset/request', [PasswordResetController::class, 'request'])->middleware('throttle:5,1,password-recovery-request:');
    Route::get('auth/password-reset/request-status', [PasswordResetController::class, 'requestStatus'])->middleware('throttle:60,1,password-recovery-status:');
    Route::post('auth/password-reset/device-challenge', [PasswordResetController::class, 'deviceChallenge'])->middleware('throttle:10,1,password-recovery-device-challenge:');
    Route::post('auth/password-reset/device-bind', [PasswordResetController::class, 'deviceBind'])->middleware('throttle:10,1,password-recovery-device-bind:');
    Route::post('auth/password-reset/complete-request', [PasswordResetController::class, 'completeRequest'])->middleware('throttle:10,1,password-recovery-complete:');
    Route::post('auth/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum', 'portal.member:session']);
    Route::middleware(['auth:sanctum', 'portal.member'])->group(function () {
        Route::post('device/challenge', [DeviceController::class, 'challenge'])->middleware('throttle:60,1');
        Route::post('device/heartbeat', [DeviceController::class, 'heartbeat'])->middleware('throttle:120,1');
        Route::get('accounts/{id}/devices', [DeviceController::class, 'index'])->whereNumber('id');
        Route::patch('accounts/{id}/devices/{deviceId}', [DeviceController::class, 'review'])->whereNumber(['id', 'deviceId']);
        Route::post('accounts/{id}/devices/{deviceId}/login-access', [DeviceController::class, 'loginAccess'])->whereNumber(['id', 'deviceId']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::get('auth/password-reset/status', [PasswordResetController::class, 'status']);
        Route::post('auth/password-reset/complete', [PasswordResetController::class, 'complete'])->middleware('throttle:10,1,password-reset-authenticated-complete:');
        Route::post('accounts/{id}/password-reset', [PasswordResetController::class, 'issue'])->whereNumber('id')->middleware('throttle:10,1,password-reset-account-issue:');
        Route::get('auth/brand-image', [AuthController::class, 'brandImage']);
        Route::get('auth/personal-image', [AuthController::class, 'personalImage']);
        Route::patch('auth/profile', [AuthController::class, 'profile']);
        Route::get('accounts', [AccountController::class, 'index']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('accounts/{id}', [AccountController::class, 'show'])->whereNumber('id');
        Route::match(['GET', 'POST'], 'account-lookup', [AccountLookupController::class, 'accounts']);
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
        Route::match(['GET', 'POST'], 'accounts/{id}/staff/{memberId}/password-reset', [StaffController::class, 'passwordReset'])->whereNumber(['id', 'memberId'])->middleware('throttle:10,1');
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
