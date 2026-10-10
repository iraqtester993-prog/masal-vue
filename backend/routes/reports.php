<?php

use App\Http\Controllers\Reports\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->group(function (): void {
    Route::get('options', [ReportController::class, 'options']);
    Route::get('summary', [ReportController::class, 'summary']);
    Route::get('sections/{section}/rows', [ReportController::class, 'rows']);
    Route::get('sections/{section}/rows/{rowKey}', [ReportController::class, 'row']);
    Route::post('export', [ReportController::class, 'export']);
});
Route::get('dashboard/summary', [ReportController::class, 'dashboard']);
Route::get('dashboard/activity', [ReportController::class, 'activity'])->name('dashboard.activity');
