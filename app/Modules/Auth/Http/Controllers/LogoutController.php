<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LogoutController
{
    public function __construct(private readonly AuthService $auth) {}

    /** POST /v1/auth/logout → 204 */
    public function __invoke(Request $request): Response
    {
        $this->auth->logout((string) AppServiceProvider::bearer($request));

        return new Response(null, 204);
    }
}
