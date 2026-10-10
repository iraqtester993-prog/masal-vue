<?php

use App\Http\Controllers\AccountAttachmentController;
use Illuminate\Support\Facades\Route;

Route::get('accounts/{id}/attachments', [AccountAttachmentController::class, 'index'])->whereNumber('id');
Route::post('accounts/{id}/attachments', [AccountAttachmentController::class, 'store'])->whereNumber('id');
Route::get('accounts/{id}/attachments/{attachment}/content', [AccountAttachmentController::class, 'content'])
    ->whereNumber('id')->whereNumber('attachment')->name('account.attachments.content');
Route::delete('accounts/{id}/attachments/{attachment}', [AccountAttachmentController::class, 'destroy'])
    ->whereNumber('id')->whereNumber('attachment');
