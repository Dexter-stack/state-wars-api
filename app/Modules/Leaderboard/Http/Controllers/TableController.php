<?php

namespace App\Modules\Leaderboard\Http\Controllers;

use App\Modules\Leaderboard\Http\Requests\TableRequest;
use App\Modules\Leaderboard\Http\Resources\TableRowResource;
use App\Modules\Leaderboard\Services\TableService;
use Illuminate\Http\JsonResponse;

final class TableController
{
    public function __construct(private readonly TableService $tables) {}

    /** GET /v1/table?period=tonight|week|all&subject=all|english|… (public, cacheable) */
    public function __invoke(TableRequest $request): JsonResponse
    {
        $t = $this->tables->table($request->period(), $request->subject());

        return TableRowResource::collection($t['rows'])->response()
            ->header('Cache-Control', "public, max-age={$t['maxAge']}");
    }
}
