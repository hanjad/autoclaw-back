<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::post('/register', [UserController::class, 'store']);
Route::post('/verify', [UserController::class,'verifyEmail']);
Route::post('/login', [UserController::class, 'login']);
