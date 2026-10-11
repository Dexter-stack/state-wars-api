<?php

use App\Modules\Leaderboard\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/table', TableController::class)->middleware('throttle:global');
