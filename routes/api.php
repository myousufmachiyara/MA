<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SaleOrderController as ApiSaleOrderController;
use App\Http\Controllers\Api\ProductController as ApiProductController;
use App\Http\Controllers\Api\HomeController as ApiHomeController;

Route::post('/booker/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'active.user'])->prefix('booker')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/home', [ApiHomeController::class, 'index']);
    Route::get('/products', [ApiProductController::class, 'index']);
    Route::get('/customers', [ApiSaleOrderController::class, 'customers']);

    Route::post('/orders/sync', [ApiSaleOrderController::class, 'sync']);
    Route::get('/orders/my', [ApiSaleOrderController::class, 'myOrders']);
    Route::get('/orders/{id}', [ApiSaleOrderController::class, 'show']);
    Route::put('/orders/{id}/cancel', [ApiSaleOrderController::class, 'cancel']);

    Route::post('/customers', [ApiSaleOrderController::class, 'storeCustomer']);

});