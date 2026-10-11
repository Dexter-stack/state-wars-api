<?php

namespace App\Modules\Questions\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Questions\Http\Requests\ListQuestionsRequest;
use App\Modules\Questions\Http\Requests\QuestionIdRequest;
use App\Modules\Questions\Http\Requests\QuestionRequest;
use App\Modules\Questions\Http\Resources\QuestionResource;
use App\Modules\Questions\Services\QuestionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AdminQuestionsController
{
    public function __construct(private readonly QuestionsService $questions) {}

    /** GET /v1/admin/questions?status=&subject=&limit=&offset= */
    public function index(ListQuestionsRequest $request): AnonymousResourceCollection
    {
        return QuestionResource::collection(
            $this->questions->list($request->status(), $request->subject(), $request->limit(), $request->offset()),
        );
    }

    /** POST /v1/admin/questions → 201 draft */
    public function store(QuestionRequest $request): JsonResponse
    {
        $q = $this->questions->create(AuthUser::of($request)->id, $request->question());

        return (new QuestionResource($q))->response()->setStatusCode(201);
    }

    /** POST /v1/admin/questions/{id}/approve (by someone other than the author) */
    public function approve(QuestionIdRequest $request): QuestionResource
    {
        return new QuestionResource($this->questions->approve(AuthUser::of($request)->id, $request->questionId()));
    }
}
