<?php

namespace App\Core\Http;

use App\Core\AppException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Every error becomes {"error":{"code","message","details?"}} with the same codes as the Node API (http/app.ts). */
final class ErrorRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        if ($e instanceof AppException) {
            return new JsonResponse($e->toBody(), $e->status);
        }

        if ($e instanceof ThrottleRequestsException) {
            return new JsonResponse(
                ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Wait a moment and try again.']],
                429,
                $e->getHeaders(),
            );
        }

        if ($e instanceof ValidationException) {
            return new JsonResponse(['error' => ['code' => 'bad_request', 'message' => $e->getMessage()]], 400);
        }

        // Fastify answers unknown routes AND known paths with the wrong method with 404.
        if ($e instanceof NotFoundHttpException || $e instanceof MethodNotAllowedHttpException) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Route not found.']], 404);
        }

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 400) {
            return new JsonResponse(['error' => ['code' => 'bad_request', 'message' => $e->getMessage()]], 400);
        }

        return new JsonResponse(['error' => ['code' => 'internal', 'message' => 'Something went wrong on our side. Try again.']], 500);
    }

    /** Expected errors are not logged; everything that becomes a 500 is. */
    public static function shouldReport(Throwable $e): bool
    {
        if ($e instanceof AppException) {
            return $e->status >= 500;
        }

        return ! ($e instanceof ThrottleRequestsException
            || $e instanceof ValidationException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException
            || ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500));
    }
}
