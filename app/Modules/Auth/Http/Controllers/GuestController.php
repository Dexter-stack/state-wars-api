<?php

namespace App\Modules\Auth\Http\Controllers;

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
        $out = $this->auth->registerGuest($request->username(), $request->stateCode(), $request->remind());

        return (new GuestSessionResource($out))->response()->setStatusCode(201);
    }
}
