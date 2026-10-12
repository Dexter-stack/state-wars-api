<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Resources\GuestSessionResource;
use App\Modules\Auth\Services\AccountService;

final class LoginController
{
    public function __construct(private readonly AccountService $accounts) {}

    /** POST /v1/auth/login (public) { identifier, password } → 200 { token, player } */
    public function __invoke(LoginRequest $request): GuestSessionResource
    {
        return new GuestSessionResource($this->accounts->login($request->identifier(), $request->password()));
    }
}
