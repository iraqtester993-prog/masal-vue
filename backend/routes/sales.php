<?php

use App\Http\Controllers\Sales\SalesController;
use Illuminate\Support\Facades\Route;

Route::prefix('sales')->group(function (): void {
    $controller = SalesController::class;
    Route::get('options', [$controller, 'options']);
    Route::get('configuration-options', [$controller, 'configurationOptions']);
    Route::get('products', [$controller, 'products']);
    Route::get('export', [$controller, 'export']);
    Route::get('summary', [$controller, 'summary']);
    Route::get('products/{id}/images/{kind}', [$controller, 'image'])->whereNumber('id')->whereIn('kind', ['product', 'provider', 'agent']);
    Route::post('device-session', [$controller, 'heartbeat']);
    Route::get('print-policy', [$controller, 'policy']);
    Route::put('print-policy', [$controller, 'savePolicy']);
    Route::get('print-rules', [$controller, 'rules']);
    Route::post('print-rules', [$controller, 'saveRule']);
    Route::put('print-rules/{id}', [$controller, 'saveRule'])->whereNumber('id');
    Route::get('limits', [$controller, 'limits']);
    Route::put('limits', [$controller, 'saveLimit']);
    Route::get('reprint-requests', [$controller, 'reprintRequests']);
    Route::post('reprint-requests/{id}/review', [$controller, 'review'])->whereNumber('id');
    Route::post('reprint-requests/{id}/escalate', [$controller, 'escalate'])->whereNumber('id');
    Route::get('receipt-layouts/{id}', [$controller, 'layout'])->whereNumber('id');
    Route::put('receipt-layouts/{id}', [$controller, 'saveLayout'])->whereNumber('id');
    Route::get('', [$controller, 'index']);
    Route::post('', [$controller, 'create']);
    Route::post('reservations', [$controller, 'reserve']);
    Route::post('reservations/{id}/issue', [$controller, 'issue'])->whereNumber('id');
    Route::post('reservations/{id}/cancel', [$controller, 'cancel'])->whereNumber('id');
    Route::get('{id}', [$controller, 'show'])->whereNumber('id');
    Route::get('{id}/receipt', [$controller, 'receipt'])->whereNumber('id');
    Route::post('{id}/print/start', [$controller, 'start'])->whereNumber('id');
    Route::post('{id}/print/result', [$controller, 'result'])->whereNumber('id');
    Route::post('{id}/print/retry', [$controller, 'retry'])->whereNumber('id');
    Route::post('{id}/reprint-request', [$controller, 'requestReprint'])->whereNumber('id');
    Route::post('{id}/deliver', [$controller, 'deliver'])->whereNumber('id');
});
