<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\AdminInviteController;

Route::post('/register', [UserController::class, 'store']);
Route::post('/verify', [UserController::class,'verifyEmail']);
Route::post('/login', [UserController::class, 'login']);
Route::post('/resend-verification', [UserController::class, 'resendVerificationEmail']);

// Public: check and accept an admin invite
Route::prefix('admin/invites')->middleware('throttle:10,1')->group(function () {
    Route::post('/accept', [AdminInviteController::class, 'accept']);
    Route::get('/check/{token}', [AdminInviteController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'user.role:vendor,admin'])->group(function () {
    Route::prefix('shops')->group(function () {
        Route::post('/create', [ShopController::class, 'store']);
    });
});

// Admin only: manage invites
Route::middleware(['auth:sanctum', 'user.role:admin'])->group(function () {
    Route::prefix('admin/invites')->group(function () {
        Route::get('/', [AdminInviteController::class, 'index']);
        Route::post('/', [AdminInviteController::class, 'store']);
        Route::delete('/{id}', [AdminInviteController::class, 'destroy']);
    });
});