<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Http\Requests\CheckUsernameRequest;
use App\Modules\Users\Services\UsersService;
use Illuminate\Http\JsonResponse;

final class UsernameCheckController
{
    public function __construct(private readonly UsersService $users) {}

    /** POST /v1/usernames/check (public) */
    public function __invoke(CheckUsernameRequest $request): JsonResponse
    {
        return new JsonResponse($this->users->checkUsername($request->username()));
    }
}
