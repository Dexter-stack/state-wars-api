<?php

use App\Modules\Users\Http\Controllers\MeController;
use App\Modules\Users\Http\Controllers\UsernameCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:global')->group(function () {
    Route::post('/v1/usernames/check', UsernameCheckController::class);

    Route::middleware('auth.player')->group(function () {
        Route::get('/v1/me', [MeController::class, 'show']);
        Route::patch('/v1/me', [MeController::class, 'update']);
    });
});
