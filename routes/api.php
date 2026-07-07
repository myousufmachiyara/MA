<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/booker/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active.user'])->prefix('booker')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Sale Order endpoints get added here when we build that module
});