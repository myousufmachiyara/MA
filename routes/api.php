<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SaleOrderController as ApiSaleOrderController;

Route::post('/booker/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active.user'])->prefix('booker')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/orders/sync', [ApiSaleOrderController::class, 'sync']);
    Route::get('/orders/my', [ApiSaleOrderController::class, 'myOrders']);
    Route::get('/customers', [ApiSaleOrderController::class, 'customers']);
});