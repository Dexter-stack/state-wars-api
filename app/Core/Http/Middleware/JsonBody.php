<?php

namespace App\Core\Http\Middleware;

use App\Core\AppException;
use App\Core\Errors;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Body parsing with the Node API's behaviour:
 *  - "Content-Type: application/json" with an empty body is treated as no body (some mobile clients send it).
 *  - Invalid JSON is a 400 (Laravel would silently treat it as empty).
 *  - Bodies over 16 KB and unsupported content types fail like they do in the Node API (Fastify's 413/415
 *    reach its generic error handler and become 500 "internal"; kept on purpose, see PLAN.md D2a).
 */
final class JsonBody
{
    public function __construct(private readonly int $limitBytes = 16 * 1024) {}

    public function handle(Request $request, Closure $next): Response
    {
        $declared = (int) $request->headers->get('Content-Length', '0');
        $content = $request->getContent();
        if ($declared > $this->limitBytes || strlen($content) > $this->limitBytes) {
            throw new AppException(500, 'internal', 'Something went wrong on our side. Try again.');
        }

        $text = trim($content);
        if ($text === '') {
            $request->setJson(new InputBag([]));

            return $next($request);
        }

        $type = strtolower((string) $request->headers->get('Content-Type', ''));
        if (str_starts_with($type, 'application/json')) {
            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw Errors::badRequest('The request body is not valid JSON.');
            }
            // A JSON scalar ("5", "\"x\"") is not an object: routes that read the body then fail validation.
            $request->setJson(new InputBag(is_array($decoded) && ! array_is_list($decoded) ? $decoded : []));

            return $next($request);
        }

        if (str_starts_with($type, 'text/plain')) {
            $request->setJson(new InputBag([])); // Fastify parses text/plain as a string: never a valid object

            return $next($request);
        }

        throw new AppException(500, 'internal', 'Something went wrong on our side. Try again.');
    }
}
