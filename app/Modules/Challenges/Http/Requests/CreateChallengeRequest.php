<?php

namespace App\Modules\Challenges\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod: { entryId: z.string().uuid() } */
final class CreateChallengeRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    public function rules(): array
    {
        return ['entryId' => ['required', 'string', 'uuid']];
    }

    public function entryId(): string
    {
        return strtolower($this->validated('entryId'));
    }
}
