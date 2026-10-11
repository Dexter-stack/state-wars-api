<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Users\Http\Requests\PatchMeRequest;
use App\Modules\Users\Http\Resources\PlayerSummaryResource;
use App\Modules\Users\Services\UsersService;
use Illuminate\Http\Request;

final class MeController
{
    public function __construct(private readonly UsersService $users) {}

    /** GET /v1/me */
    public function show(Request $request): PlayerSummaryResource
    {
        return new PlayerSummaryResource($this->users->summary(AuthUser::of($request)->id));
    }

    /** PATCH /v1/me */
    public function update(PatchMeRequest $request): PlayerSummaryResource
    {
        $id = AuthUser::of($request)->id;
        if ($request->stateCode() !== null && $request->stateCode() !== '') {
            $this->users->changeState($id, $request->stateCode());
        }
        if ($request->remind() !== null) {
            $this->users->setRemind($id, $request->remind());
        }

        return new PlayerSummaryResource($this->users->summary($id));
    }
}
