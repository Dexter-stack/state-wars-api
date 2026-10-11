<?php

namespace App\Modules\Battles\Http\Requests;

use App\Core\Http\Rules\StrictInteger;

/**
 * zod: params { id: uuid } + body { index: z.number().int().min(0).max(19), choice: z.number().int().min(0).max(3).nullable() }
 * `choice` must be present: null means "no answer" (time ran out on the phone).
 */
final class AnswerRequest extends EntryIdRequest
{
    protected function source(): array
    {
        return array_merge($this->json()->all(), $this->routeParams());
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'uuid'],
            'index' => ['present', new StrictInteger(0, 19)],
            'choice' => ['present', 'nullable', new StrictInteger(0, 3)],
        ];
    }

    public function index(): int
    {
        return StrictInteger::toInt($this->validated('index'));
    }

    public function choice(): ?int
    {
        $c = $this->validated('choice');

        return $c === null ? null : StrictInteger::toInt($c);
    }
}
