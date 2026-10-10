<?php

use App\Http\Controllers\Finance\FinanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('finance')->group(function (): void {
    $controller = FinanceController::class;
    foreach (['options', 'wallets', 'ledger', 'invoices', 'prices'] as $path) {
        Route::get($path, [$controller, $path]);
    }
    Route::get('price-template', [$controller, 'priceTemplate']);
    Route::post('deposits', [$controller, 'deposits']);
    Route::post('transfers', [$controller, 'transfers']);
    Route::get('transfers', [$controller, 'transferHistory']);
    Route::get('recoveries', [$controller, 'recoveries']);
    Route::post('transfers/{id}/recover', [$controller, 'recoverTransfer'])->whereNumber('id');
    Route::post('bulk-transfers', [$controller, 'bulkTransfers']);
    Route::get('funding-requests', [$controller, 'fundingRequests']);
    Route::post('funding-requests', [$controller, 'storeFundingRequest']);
    Route::post('funding-requests/{id}/review', [$controller, 'reviewFundingRequest'])->whereNumber('id');
    Route::post('funding-requests/{id}/cancel', [$controller, 'cancelFundingRequest'])->whereNumber('id');
    Route::get('funding-policy', [$controller, 'policy']);
    Route::put('funding-policy', [$controller, 'savePolicy']);
    Route::post('invoices', [$controller, 'storeInvoice']);
    Route::post('invoices/{id}/settle', [$controller, 'settleInvoice'])->whereNumber('id');
    Route::get('price-requests', [$controller, 'priceRequests']);
    Route::post('price-requests', [$controller, 'storePriceRequest']);
    Route::post('price-requests/{id}/review', [$controller, 'reviewPriceRequest'])->whereNumber('id');
    Route::post('price-requests/{id}/reverse', [$controller, 'reversePriceRequest'])->whereNumber('id');
});
