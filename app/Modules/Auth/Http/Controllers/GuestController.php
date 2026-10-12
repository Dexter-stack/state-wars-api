<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Core\Errors;
use App\Modules\Auth\Http\Requests\GuestRequest;
use App\Modules\Auth\Http\Resources\GuestSessionResource;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\JsonResponse;

final class GuestController
{
    public function __construct(private readonly AuthService $auth) {}

    /** POST /v1/auth/guest (public) → 201 { token, player } */
    public function __invoke(GuestRequest $request): JsonResponse
    {
        // Accounts need an email and a password (POST /v1/auth/signup). Guest sign-up stays only for the
        // Node test scripts and the test suite, behind GUEST_SIGNUP.
        if (! config('statewars.guest_signup')) {
            throw Errors::gone('Guest sign-up is closed. Create an account with your email and a password.');
        }
        $out = $this->auth->registerGuest($request->username(), $request->stateCode(), $request->remind());

        return (new GuestSessionResource($out))->response()->setStatusCode(201);
    }
}
