<?php

use App\Modules\Questions\Http\Controllers\AdminBattlesController;
use App\Modules\Questions\Http\Controllers\AdminQuestionsController;
use Illuminate\Support\Facades\Route;

// Admin only: question bank and battle scheduling.
Route::middleware(['throttle:global', 'auth.player', 'auth.admin'])->group(function () {
    Route::get('/v1/admin/questions', [AdminQuestionsController::class, 'index']);
    Route::post('/v1/admin/questions', [AdminQuestionsController::class, 'store']);
    Route::post('/v1/admin/questions/{id}/approve', [AdminQuestionsController::class, 'approve']);
    Route::post('/v1/admin/battles', [AdminBattlesController::class, 'store']);
    Route::post('/v1/admin/days/{date}/prepare', [AdminBattlesController::class, 'prepare']);
});
