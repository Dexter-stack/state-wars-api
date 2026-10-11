<?php

use App\Modules\Challenges\Http\Controllers\ChallengesController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:global', 'auth.player'])->group(function () {
    Route::get('/v1/challenges', [ChallengesController::class, 'index']);
    Route::post('/v1/challenges', [ChallengesController::class, 'store']);
    Route::post('/v1/challenges/{id}/accept', [ChallengesController::class, 'accept']);
});
