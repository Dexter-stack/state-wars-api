<?php

use App\Modules\Stats\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/stats', StatsController::class)->middleware(['throttle:global', 'auth.player']);
