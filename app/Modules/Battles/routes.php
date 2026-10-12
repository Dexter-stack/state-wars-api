<?php

use App\Modules\Battles\Http\Controllers\EntriesController;
use Illuminate\Support\Facades\Route;

// Path parameters are NOT constrained here: the FormRequests validate them, so a bad uuid is a 400 (as in Node).
Route::middleware(['throttle:global', 'auth.player'])->group(function () {
    Route::post('/v1/entries', [EntriesController::class, 'start']);
    Route::get('/v1/entries/latest', [EntriesController::class, 'latest']);
    Route::get('/v1/entries/{id}/current', [EntriesController::class, 'current']);
    Route::post('/v1/entries/{id}/finish', [EntriesController::class, 'finish']);
    Route::get('/v1/entries/{id}/result', [EntriesController::class, 'result']);
    Route::get('/v1/entries/{id}/reveal/{n}', [EntriesController::class, 'reveal']);
});

// Answers have their own limit (40/min per player), which replaces the global one for this route.
Route::post('/v1/entries/{id}/answers', [EntriesController::class, 'answer'])->middleware(['throttle:answers', 'auth.player']);
