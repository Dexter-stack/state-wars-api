<?php

namespace App\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The headers @fastify/helmet sets by default (contentSecurityPolicy disabled, as in the Node API). */
final class SecurityHeaders
{
    private const HEADERS = [
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'Origin-Agent-Cluster' => '?1',
        'Referrer-Policy' => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=15552000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
        'X-DNS-Prefetch-Control' => 'off',
        'X-Download-Options' => 'noopen',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'X-XSS-Protection' => '0',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
