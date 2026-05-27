<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ServiceRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::prefix('service-requests')->group(function () {
    Route::post('/', [ServiceRequestController::class, 'store']);
    Route::get('/pending', [ServiceRequestController::class, 'pending']);
    Route::get('/history', [ServiceRequestController::class, 'history']);
});

Route::prefix('dispatch')->group(function () {
    Route::get('/queue', [ServiceRequestController::class, 'trackingQueue']);
    Route::get('/priority-queue', [ServiceRequestController::class, 'priorityQueue']);
    Route::get('/incoming-queue', [ServiceRequestController::class, 'pending']);
});
