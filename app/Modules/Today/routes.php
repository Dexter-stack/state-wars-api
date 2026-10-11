<?php

use App\Modules\Today\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:global', 'auth.player'])->group(function () {
    Route::get('/v1/today', [TodayController::class, 'today']);
    Route::get('/v1/subjects', [TodayController::class, 'subjects']);
});
