<?php

namespace App\Modules\Battles\Http\Controllers;

use App\Core\Errors;
use App\Modules\Auth\AuthUser;
use App\Modules\Battles\Http\Requests\AnswerRequest;
use App\Modules\Battles\Http\Requests\EntryIdRequest;
use App\Modules\Battles\Http\Requests\RevealRequest;
use App\Modules\Battles\Http\Requests\StartRequest;
use App\Modules\Battles\Http\Resources\AnswerResultResource;
use App\Modules\Battles\Http\Resources\BattleResultResource;
use App\Modules\Battles\Http\Resources\BattleStartResource;
use App\Modules\Battles\Http\Resources\ResumeResource;
use App\Modules\Battles\Http\Resources\RevealResource;
use App\Modules\Battles\Services\BattlesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Thin: validate, call one service method, return the contract shape. */
final class EntriesController
{
    public function __construct(private readonly BattlesService $battles) {}

    /** POST /v1/entries { league? } → 201 first question (or resumes) */
    public function start(StartRequest $request): JsonResponse
    {
        $out = $this->battles->start(AuthUser::of($request)->id, $request->league());

        return (new BattleStartResource($out))->response()->setStatusCode(201);
    }

    /** GET /v1/entries/latest */
    public function latest(Request $request): JsonResponse
    {
        $latest = $this->battles->latest(AuthUser::of($request)->id);
        if ($latest === null) {
            throw Errors::notFound('Finished battle');
        }

        return new JsonResponse($latest);
    }

    /** GET /v1/entries/{id}/current */
    public function current(EntryIdRequest $request): ResumeResource
    {
        return new ResumeResource($this->battles->resume(AuthUser::of($request)->id, $request->entryId()));
    }

    /** POST /v1/entries/{id}/answers { index, choice } */
    public function answer(AnswerRequest $request): AnswerResultResource
    {
        return new AnswerResultResource(
            $this->battles->answer(AuthUser::of($request)->id, $request->entryId(), $request->index(), $request->choice()),
        );
    }

    /** POST /v1/entries/{id}/finish */
    public function finish(EntryIdRequest $request): BattleResultResource
    {
        return new BattleResultResource($this->battles->finish(AuthUser::of($request)->id, $request->entryId()));
    }

    /** GET /v1/entries/{id}/reveal/{n} → 425 too_early until everyone has finished */
    public function reveal(RevealRequest $request): RevealResource
    {
        return new RevealResource($this->battles->reveal(AuthUser::of($request)->id, $request->entryId(), $request->n()));
    }
}
