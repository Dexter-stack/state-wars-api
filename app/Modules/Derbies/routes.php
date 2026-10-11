<?php

use App\Modules\Derbies\Http\Controllers\DerbiesController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/derbies', DerbiesController::class)->middleware(['throttle:global', 'auth.player']);
