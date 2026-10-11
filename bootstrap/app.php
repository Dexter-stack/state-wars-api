<?php

use App\Core\Http\ErrorRenderer;
use App\Core\Http\HealthController;
use App\Core\Http\Middleware\JsonBody;
use App\Core\Http\Middleware\RequestId;
use App\Core\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\InvokeDeferredCallbacks;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// HTTP pipeline (port of the Node src/http/app.ts):
//   request id → trusted proxies → CORS → security headers → JSON body rules
//   → module routes (auth middleware, rate limit, FormRequest validation) → service.
// Module routes are registered by each module's ServiceProvider (bootstrap/providers.php).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // liveness and readiness: no auth, no rate limit
            Route::get('/health', [HealthController::class, 'health']);
            Route::get('/ready', [HealthController::class, 'ready']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Replaces Laravel's default global stack on purpose: no TrimStrings / ConvertEmptyStringsToNull
        // (zod trims only the fields that say so, and "" is not null).
        $middleware->use([
            InvokeDeferredCallbacks::class,
            RequestId::class,
            TrustProxies::class,
            HandleCors::class,
            SecurityHeaders::class,
            JsonBody::class,
        ]);
        $middleware->trustProxies(at: '*'); // behind a load balancer: use X-Forwarded-For for rate limits
        $middleware->throttleWithRedis(); // limits shared by every API instance
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (Throwable $e) => ! ErrorRenderer::shouldReport($e));
        $exceptions->shouldRenderJsonWhen(fn () => true);
        $exceptions->render(fn (Throwable $e, Request $request) => ErrorRenderer::render($e));
    })->create();
