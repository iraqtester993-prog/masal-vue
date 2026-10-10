<?php

use App\Http\Controllers\Stock\StockController;
use Illuminate\Support\Facades\Route;

Route::get('stock/options', [StockController::class, 'options']);
Route::get('stock/summary', [StockController::class, 'summary']);
Route::get('stock/orders', [StockController::class, 'orders']);
Route::get('stock/orders/{id}', [StockController::class, 'showOrder'])->whereNumber('id');
Route::post('stock/orders/preview', [StockController::class, 'preview']);
Route::post('stock/orders', [StockController::class, 'submit']);
Route::post('stock/orders/{id}/resubmit', [StockController::class, 'resubmit'])->whereNumber('id');
Route::post('stock/orders/{id}/review', [StockController::class, 'review'])->whereNumber('id');
Route::get('stock/batches', [StockController::class, 'batches']);
Route::get('stock/batches/{id}', [StockController::class, 'batch'])->whereNumber('id');
Route::get('stock/batches/{id}/cards', [StockController::class, 'cards'])->whereNumber('id');
Route::get('stock/batches/{id}/selection', [StockController::class, 'selection'])->whereNumber('id');
Route::post('stock/batches/{id}/actions', [StockController::class, 'batchAction'])->whereNumber('id');
Route::post('stock/batches/{id}/actions/preview', [StockController::class, 'previewAction'])->whereNumber('id');
Route::post('stock/batches/{id}/copy', [StockController::class, 'copy'])->whereNumber('id');
Route::post('stock/batches/{id}/claims', [StockController::class, 'claim'])->whereNumber('id');
Route::get('stock/claims', [StockController::class, 'claims']);
Route::post('stock/claims/{id}/settle', [StockController::class, 'settleClaim'])->whereNumber('id');
Route::get('stock/adjustments', [StockController::class, 'adjustments']);
Route::get('stock/withdrawals', [StockController::class, 'withdrawals']);
Route::post('stock/withdrawals', [StockController::class, 'requestWithdrawal']);
Route::post('stock/withdrawals/{id}/review', [StockController::class, 'reviewWithdrawal'])->whereNumber('id');
Route::post('stock/withdrawals/{id}/download', [StockController::class, 'downloadWithdrawal'])->whereNumber('id');
