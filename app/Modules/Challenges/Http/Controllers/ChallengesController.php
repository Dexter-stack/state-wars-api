<?php

namespace App\Modules\Challenges\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Challenges\Http\Requests\ChallengeIdRequest;
use App\Modules\Challenges\Http\Requests\CreateChallengeRequest;
use App\Modules\Challenges\Http\Resources\ChallengeResource;
use App\Modules\Challenges\Services\ChallengesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ChallengesController
{
    public function __construct(private readonly ChallengesService $challenges) {}

    /** GET /v1/challenges */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ChallengeResource::collection($this->challenges->list(AuthUser::of($request)->id));
    }

    /** POST /v1/challenges { entryId } → 201 { id, url } */
    public function store(CreateChallengeRequest $request): JsonResponse
    {
        return new JsonResponse($this->challenges->create(AuthUser::of($request)->id, $request->entryId()), 201);
    }

    /** POST /v1/challenges/{id}/accept → 204 */
    public function accept(ChallengeIdRequest $request): Response
    {
        $this->challenges->accept(AuthUser::of($request)->id, $request->challengeId());

        return new Response(null, 204);
    }
}
