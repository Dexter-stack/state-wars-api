<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\EmailRequest;
use App\Modules\Auth\Http\Requests\SignupRequest;
use App\Modules\Auth\Http\Resources\GuestSessionResource;
use App\Modules\Auth\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

final class SignupController
{
    public function __construct(private readonly AccountService $accounts) {}

    /** POST /v1/auth/signup/email (public) { email } → 204, a code is emailed */
    public function requestEmail(EmailRequest $request): Response
    {
        $this->accounts->requestSignupCode($request->email());

        return new Response(null, 204);
    }

    /** POST /v1/auth/signup (public) { username, stateCode, remind?, email, code, password } → 201 { token, player } */
    public function store(SignupRequest $request): JsonResponse
    {
        $out = $this->accounts->signUp(
            $request->username(), $request->stateCode(), $request->remind(),
            $request->email(), $request->code(), $request->password(),
        );

        return (new GuestSessionResource($out))->response()->setStatusCode(201);
    }
}
