<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (\Illuminate\Http\Request $request) => $request->user());

    Route::get('/receipts', [ReceiptController::class, 'index']);
    Route::post('/receipts', [ReceiptController::class, 'store']);
    Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy']);
    Route::post('/receipts/manual', [ReceiptController::class, 'storeManual']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::post('/dashboard/income', [DashboardController::class, 'setIncome']);
    Route::delete('/dashboard/income/{income}', [DashboardController::class, 'deleteIncome']);
    Route::get('/dashboard/income-history', [DashboardController::class, 'incomeHistory']);

    Route::post('/chat', [ChatController::class, 'ask']);
});
