<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Core\Errors;
use App\Modules\Auth\AuthUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after Authenticate. Port of the Node `requireAdmin` preHandler. */
final class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AuthUser::of($request)->role !== 'admin') {
            throw Errors::forbidden();
        }

        return $next($request);
    }
}
