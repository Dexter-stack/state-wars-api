<?php

use App\Modules\Auth\Http\Controllers\AccountController;
use App\Modules\Auth\Http\Controllers\GuestController;
use App\Modules\Auth\Http\Controllers\LoginController;
use App\Modules\Auth\Http\Controllers\LogoutController;
use App\Modules\Auth\Http\Controllers\PasswordController;
use App\Modules\Auth\Http\Controllers\SignupController;
use Illuminate\Support\Facades\Route;

// Sign-up has its own hourly limit per IP, which replaces the global one (as in the Node route config).
// Asking for the sign-up code shares the per-minute sign-in bucket instead, so it doesn't halve that allowance.
Route::post('/v1/auth/signup/email', [SignupController::class, 'requestEmail'])->middleware('throttle:signin');
Route::post('/v1/auth/signup', [SignupController::class, 'store'])->middleware('throttle:auth');
// Closed unless GUEST_SIGNUP=true (410 gone): kept for the Node test scripts and the test suite.
Route::post('/v1/auth/guest', GuestController::class)->middleware('throttle:auth');
Route::post('/v1/auth/logout', LogoutController::class)->middleware(['throttle:global', 'auth.player']);

// Signing in to an existing account and recovering it: public, limited per IP.
Route::middleware('throttle:signin')->group(function () {
    Route::post('/v1/auth/login', LoginController::class);
    Route::post('/v1/auth/password/forgot', [PasswordController::class, 'forgot']);
    Route::post('/v1/auth/password/reset', [PasswordController::class, 'reset']);
});

// Securing a guest account with a verified email and a password.
Route::middleware(['throttle:global', 'auth.player'])->group(function () {
    Route::get('/v1/auth/account', [AccountController::class, 'show']);
    Route::post('/v1/auth/account/email', [AccountController::class, 'requestEmail']);
    Route::post('/v1/auth/account/confirm', [AccountController::class, 'confirm']);
    Route::post('/v1/auth/password/change', [PasswordController::class, 'change']);
});
