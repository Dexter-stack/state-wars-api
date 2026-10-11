<?php

namespace App\Modules\Challenges\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod IdParams: { id: z.string().uuid() } */
final class ChallengeIdRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->routeParams();
    }

    public function rules(): array
    {
        return ['id' => ['required', 'string', 'uuid']];
    }

    public function challengeId(): string
    {
        return strtolower($this->validated('id'));
    }
}
