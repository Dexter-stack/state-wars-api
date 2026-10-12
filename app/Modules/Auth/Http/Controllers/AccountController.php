<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Auth\Http\Requests\EmailCodePasswordRequest;
use App\Modules\Auth\Http\Requests\EmailRequest;
use App\Modules\Auth\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountController
{
    public function __construct(private readonly AccountService $accounts) {}

    /** GET /v1/auth/account → { secured, email? } */
    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->accounts->status(AuthUser::of($request)->id));
    }

    /** POST /v1/auth/account/email { email } → 204, a code is emailed */
    public function requestEmail(EmailRequest $request): Response
    {
        $this->accounts->requestEmail(AuthUser::of($request)->id, $request->email());

        return new Response(null, 204);
    }

    /** POST /v1/auth/account/confirm { email, code, password } → { secured: true, email } */
    public function confirm(EmailCodePasswordRequest $request): JsonResponse
    {
        return new JsonResponse(
            $this->accounts->confirm(AuthUser::of($request)->id, $request->email(), $request->code(), $request->password()),
        );
    }
}
