<?php

use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Support\SupportController;
use Illuminate\Support\Facades\Route;

Route::prefix('support')->group(function (): void {
    Route::get('site-inquiries', [CompanyController::class, 'inquiries']);
    Route::get('site-inquiries/{id}', [CompanyController::class, 'inquiryDetail'])->whereNumber('id');
    Route::post('site-inquiries/{id}/replies', [CompanyController::class, 'reply'])->whereNumber('id');
    Route::patch('site-inquiries/{id}', [CompanyController::class, 'review'])->whereNumber('id');
    Route::get('options', [SupportController::class, 'options']);
    Route::get('tickets', [SupportController::class, 'index'])->defaults('support_action', 'index');
    Route::post('tickets', [SupportController::class, 'create'])->defaults('support_action', 'create');
    Route::post('broadcasts', [SupportController::class, 'broadcast'])->defaults('support_action', 'broadcast');
    Route::get('tickets/{id}', [SupportController::class, 'show'])->whereNumber('id');
    Route::post('tickets/{id}/replies', [SupportController::class, 'reply'])->whereNumber('id')->defaults('support_action', 'reply');
    Route::post('tickets/{id}/status', [SupportController::class, 'status'])->whereNumber('id')->defaults('support_action', 'status');
    Route::post('tickets/{id}/read', [SupportController::class, 'read'])->whereNumber('id');
    Route::get('phones/{id}', [SupportController::class, 'phones'])->whereNumber('id');
    Route::put('phones/{id}', [SupportController::class, 'savePhones'])->whereNumber('id')->defaults('support_action', 'phones');
    Route::post('attachments', [SupportController::class, 'upload']);
    Route::get('attachments/{id}', [SupportController::class, 'attachment'])->whereNumber('id');
});
Route::prefix('notifications')->group(function (): void {
    Route::get('options', [NotificationController::class, 'options']);
    Route::get('summary', [NotificationController::class, 'summary']);
    Route::get('export', [NotificationController::class, 'export'])->defaults('support_action', 'export');
    Route::get('/', [NotificationController::class, 'index'])->defaults('support_action', 'notices');
    Route::post('/', [NotificationController::class, 'create'])->defaults('support_action', 'notice-create');
    Route::post('read-page', [NotificationController::class, 'readPage'])->defaults('support_action', 'read-page');
    Route::post('{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
});
