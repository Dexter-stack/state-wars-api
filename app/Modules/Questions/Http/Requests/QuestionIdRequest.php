<?php

namespace App\Modules\Questions\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod IdParams: { id: z.string().uuid() } */
final class QuestionIdRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->routeParams();
    }

    public function rules(): array
    {
        return ['id' => ['required', 'string', 'uuid']];
    }

    public function questionId(): string
    {
        return strtolower($this->validated('id'));
    }
}
