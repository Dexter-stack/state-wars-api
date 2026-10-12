<?php

use App\Core\Http\WelcomeController;
use Illuminate\Support\Facades\Route;

// Pages for people, not for the app: what you see when you open the API's base URL in a browser.
// Loaded without Laravel's "web" middleware group on purpose: no sessions, cookies or CSRF are needed here.
Route::get('/', WelcomeController::class)->middleware('throttle:global');
