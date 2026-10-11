<?php

namespace App\Modules\Questions\Http\Controllers;

use App\Modules\Battles\Services\SchedulerService;
use App\Modules\Questions\Http\Requests\PrepareDayRequest;
use App\Modules\Questions\Http\Requests\ScheduleBattleRequest;
use Illuminate\Http\JsonResponse;

final class AdminBattlesController
{
    public function __construct(private readonly SchedulerService $scheduler) {}

    /** POST /v1/admin/battles → 201 BattleView */
    public function store(ScheduleBattleRequest $request): JsonResponse
    {
        $b = $this->scheduler->schedule($request->battleDate(), $request->kind(), $request->subject(), $request->questionIds());

        return new JsonResponse($b->toArray(), 201);
    }

    /** POST /v1/admin/days/{date}/prepare → { created, skipped } */
    public function prepare(PrepareDayRequest $request): JsonResponse
    {
        return new JsonResponse($this->scheduler->prepareDay($request->battleDate()));
    }
}
