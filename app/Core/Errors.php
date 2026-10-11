<?php

namespace App\Core;

/** Factories for expected failures, with messages a player can understand. Same codes as the Node API. */
final class Errors
{
    public static function badRequest(string $message, mixed $details = null): AppException
    {
        return new AppException(400, 'bad_request', $message, $details);
    }

    public static function unauthorized(string $message = 'Sign in to continue.'): AppException
    {
        return new AppException(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = "You can't do that."): AppException
    {
        return new AppException(403, 'forbidden', $message);
    }

    public static function notFound(string $what): AppException
    {
        return new AppException(404, 'not_found', "{$what} not found.");
    }

    public static function conflict(string $code, string $message): AppException
    {
        return new AppException(409, $code, $message);
    }

    public static function tooEarly(string $message): AppException
    {
        return new AppException(425, 'too_early', $message);
    }

    public static function gone(string $message): AppException
    {
        return new AppException(410, 'gone', $message);
    }
}
