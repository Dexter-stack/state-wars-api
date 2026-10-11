<?php

namespace App\Modules\Stats\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Stats\Http\Resources\StatsPageResource;
use App\Modules\Stats\Services\StatsService;
use Illuminate\Http\Request;

final class StatsController
{
    public function __construct(private readonly StatsService $stats) {}

    /** GET /v1/stats */
    public function __invoke(Request $request): StatsPageResource
    {
        return new StatsPageResource($this->stats->page(AuthUser::of($request)->id));
    }
}
