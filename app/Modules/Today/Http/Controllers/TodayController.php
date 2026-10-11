<?php

namespace App\Modules\Today\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Today\Http\Resources\SubjectResource;
use App\Modules\Today\Http\Resources\TodayResource;
use App\Modules\Today\Services\TodayService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TodayController
{
    public function __construct(private readonly TodayService $today) {}

    /** GET /v1/today */
    public function today(Request $request): TodayResource
    {
        return new TodayResource($this->today->today(AuthUser::of($request)->id));
    }

    /** GET /v1/subjects */
    public function subjects(Request $request): AnonymousResourceCollection
    {
        return SubjectResource::collection($this->today->subjects(AuthUser::of($request)->id));
    }
}
