<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Core\Errors;
use App\Modules\Auth\AuthUser;
use App\Modules\Auth\Services\AuthService;
use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token → signed-in player (cached). Port of the Node `authenticate` preHandler. */
final class Authenticate
{
    public function __construct(private readonly AuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = AppServiceProvider::bearer($request);
        if ($token === null || $token === '') {
            throw Errors::unauthorized();
        }
        $request->attributes->set(AuthUser::ATTRIBUTE, $this->auth->authenticate($token));

        return $next($request);
    }
}
