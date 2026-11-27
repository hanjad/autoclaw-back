<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\AdminShopController;
use App\Http\Controllers\VendorShopController;
use App\Http\Controllers\AdminInviteController;
use App\Http\Controllers\ProductDiscountController;

// Auth (rate limiters are defined in AppServiceProvider; login limits itself in UserController)
Route::post('/register', [UserController::class, 'store'])->middleware('throttle:register');
Route::post('/verify', [UserController::class, 'verifyEmail'])->middleware('throttle:verify');
Route::post('/login', [UserController::class, 'login']);
Route::post('/resend-verification', [UserController::class, 'resendVerificationEmail'])->middleware('throttle:resend');
Route::post('/logout', [UserController::class, 'logout'])->middleware('auth:sanctum');

// Public: browse the catalogue
Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/categories', [ProductController::class, 'categories']);
    Route::get('/{id}', [ProductController::class, 'show'])->whereNumber('id');
});

// Public: check and accept an admin invite
Route::prefix('admin/invites')->middleware('throttle:10,1')->group(function () {
    Route::post('/accept', [AdminInviteController::class, 'accept']);
    Route::get('/check/{token}', [AdminInviteController::class, 'show']);
});

// Vendors and admins
Route::middleware(['auth:sanctum', 'user.role:vendor,admin'])->group(function () {
    Route::get('/vendor/products', [ProductController::class, 'mine']);

    Route::prefix('products')->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::put('/{id}', [ProductController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [ProductController::class, 'destroy'])->whereNumber('id');
        Route::put('/{id}/discount', [ProductDiscountController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}/discount', [ProductDiscountController::class, 'destroy'])->whereNumber('id');
    });
});

// Vendors only: their own shop and its verification
Route::middleware(['auth:sanctum', 'user.role:vendor'])->prefix('vendor')->group(function () {
    Route::get('/shop', [VendorShopController::class, 'show']);
    Route::post('/shop', [VendorShopController::class, 'store']);
    Route::put('/shop', [VendorShopController::class, 'update']);          // POST with _method=PUT when uploading a logo
    Route::post('/shop/verification', [VendorShopController::class, 'submitVerification'])->middleware('throttle:10,60');
    Route::get('/banks', [VendorShopController::class, 'banks']);
});

// Admin only
Route::middleware(['auth:sanctum', 'user.role:admin'])->group(function () {
    Route::prefix('admin/invites')->group(function () {
        Route::get('/', [AdminInviteController::class, 'index']);
        Route::post('/', [AdminInviteController::class, 'store']);
        Route::delete('/{id}', [AdminInviteController::class, 'destroy']);
    });

    Route::prefix('admin/shops')->group(function () {
        Route::get('/', [AdminShopController::class, 'index']);
        Route::get('/{id}/documents/{type}', [AdminShopController::class, 'document'])
            ->whereNumber('id')
            ->where('type', 'id_document|selfie|address_proof|cac_certificate');
        Route::put('/{id}/verification', [AdminShopController::class, 'verification'])->whereNumber('id');
    });
});
