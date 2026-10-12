<?php

namespace App\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET / : a welcome page for someone who opens the API's address in a browser, and a small JSON answer
 * for a client that asks for JSON. Says nothing about the environment, versions or configuration.
 */
final class WelcomeController
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['name' => 'State Wars API', 'ok' => true, 'api' => '/v1', 'health' => '/health']);
        }

        return new Response(view('welcome', ['webUrl' => (string) config('statewars.public_web_url')]));
    }
}
