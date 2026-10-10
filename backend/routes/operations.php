<?php

use App\Http\Controllers\Operations\OperationController;
use Illuminate\Support\Facades\Route;

Route::prefix('operations')->group(function (): void {
    Route::get('security', [OperationController::class, 'security'])->defaults('operation_action', 'security');
    Route::post('security/stops', [OperationController::class, 'createStop'])->defaults('operation_action', 'stop-create');
    Route::post('security/stops/{id}/resume', [OperationController::class, 'resume'])->whereNumber('id')->defaults('operation_action', 'resume');
    Route::put('security/accounts/{id}', [OperationController::class, 'direct'])->whereNumber('id')->defaults('operation_action', 'direct');
    Route::post('security/restore-global', [OperationController::class, 'restoreGlobal'])->defaults('operation_action', 'restore-global');
    Route::get('account-times', [OperationController::class, 'times']);
    Route::get('account-times/{id}', [OperationController::class, 'time'])->whereNumber('id');
    Route::put('account-times/{id}', [OperationController::class, 'saveTime'])->whereNumber('id')->defaults('operation_action', 'time-save');
    Route::post('activity', [OperationController::class, 'activity']);
    Route::get('archive', [OperationController::class, 'archives'])->defaults('operation_action', 'archive-index');
    Route::get('archive/accounts/{id}/eligibility', [OperationController::class, 'eligibility'])->whereNumber('id');
    Route::post('archive/accounts/{id}', [OperationController::class, 'createArchive'])->whereNumber('id')->defaults('operation_action', 'archive-create');
    Route::get('archive/{id}', [OperationController::class, 'archive'])->whereNumber('id');
    Route::get('archive/{id}/image', [OperationController::class, 'image'])->whereNumber('id');
});
