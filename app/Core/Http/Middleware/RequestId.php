<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Uses the caller's X-Request-Id (e.g. from the load balancer) or makes one; adds it to logs and the response. */
final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->headers->get('X-Request-Id', '');
        if ($id === '' || strlen($id) > 128) {
            $id = (string) Str::uuid();
        }
        $request->attributes->set('requestId', $id);
        Log::withContext(['reqId' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
