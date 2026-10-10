<?php

use App\Http\Controllers\ImportReadController;
use Illuminate\Support\Facades\Route;

Route::post('stock/read', ImportReadController::class);
