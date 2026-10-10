<?php

use App\Http\Controllers\Backups\BackupController;
use Illuminate\Support\Facades\Route;

Route::prefix('backups')->group(function (): void {
    Route::get('/', [BackupController::class, 'index'])->name('backups.index');
    Route::post('/', [BackupController::class, 'create'])->middleware('throttle:2,1,backup-create:')->name('backups.create');
    Route::post('previews', [BackupController::class, 'preview'])->middleware('throttle:4,1,backup-preview:')->name('backups.preview');
    Route::post('restore-jobs', [BackupController::class, 'restore'])->middleware('throttle:4,1,backup-restore:')->name('backups.restore');
    Route::get('restore-jobs/{id}', [BackupController::class, 'job'])->whereUuid('id')->name('backups.job');
    Route::get('{id}/download', [BackupController::class, 'download'])->whereUuid('id')->name('backups.download');
});
