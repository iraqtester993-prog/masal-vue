<?php

use App\Http\Controllers\Digital\DigitalController;
use App\Http\Controllers\Digital\TopupCategoryController;
use App\Http\Controllers\Digital\TopupDistributionController;
use Illuminate\Support\Facades\Route;

Route::prefix('digital')->group(function (): void {
    $controller = DigitalController::class;
    Route::get('options', [$controller, 'options']);
    Route::get('connections', [$controller, 'connections']);
    Route::post('connections', [$controller, 'storeConnection']);
    Route::put('connections/{id}', [$controller, 'updateConnection'])->whereNumber('id');
    Route::post('connections/{id}/catalog', [$controller, 'catalog'])->whereNumber('id');
    Route::post('connections/{id}/sync', [$controller, 'synchronize'])->whereNumber('id');
    Route::post('connections/{id}/balance', [$controller, 'balance'])->whereNumber('id');
    Route::put('connections/{id}/grants/{target}', [$controller, 'grant'])->whereNumber(['id', 'target']);
    Route::get('offers', [$controller, 'offers']);
    Route::get('history', [$controller, 'history']);
    Route::post('device-session', [$controller, 'heartbeat']);
    Route::get('orders/summary', [$controller, 'summary']);
    Route::get('orders/recovery', [$controller, 'recovery']);
    Route::get('orders/export', [$controller, 'export']);
    Route::get('orders', [$controller, 'index']);
    Route::post('orders', [$controller, 'store']);
    Route::get('orders/{id}', [$controller, 'show'])->whereNumber('id');
    Route::post('orders/{id}/acknowledge', [$controller, 'acknowledge'])->whereNumber('id');
    Route::post('orders/{id}/verify', [$controller, 'verify'])->whereNumber('id');
    Route::post('orders/{id}/refund-status', [$controller, 'refundStatus'])->whereNumber('id');
    Route::get('orders/{id}/receipt', [$controller, 'receipt'])->whereNumber('id');
    Route::get('orders/{id}/print-authorization', [$controller, 'printAuthorization'])->whereNumber('id');
});

Route::prefix('topup')->group(function (): void {
    Route::get('distribution', [TopupDistributionController::class, 'index']);
    Route::put('distribution/{target}', [TopupDistributionController::class, 'update'])->whereNumber('target');
    Route::get('available', [TopupDistributionController::class, 'available']);
    Route::put('prices', [TopupDistributionController::class, 'prices']);
    $controller = TopupCategoryController::class;
    Route::get('categories', [$controller, 'index']);
});
