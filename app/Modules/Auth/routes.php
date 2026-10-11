<?php

use App\Modules\Auth\Http\Controllers\GuestController;
use App\Modules\Auth\Http\Controllers\LogoutController;
use Illuminate\Support\Facades\Route;

// Sign-up has its own hourly limit per IP, which replaces the global one (as in the Node route config).
Route::post('/v1/auth/guest', GuestController::class)->middleware('throttle:auth');
Route::post('/v1/auth/logout', LogoutController::class)->middleware(['throttle:global', 'auth.player']);
