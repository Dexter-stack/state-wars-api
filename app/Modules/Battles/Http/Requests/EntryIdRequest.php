<?php

namespace App\Modules\Battles\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod EntryParams: { id: z.string().uuid() } — a malformed id is a 400, not a 404. */
class EntryIdRequest extends ApiFormRequest
{
    /** Path parameters only (finish/current ignore any body, like the Node routes). */
    protected function source(): array
    {
        return $this->routeParams();
    }

    public function rules(): array
    {
        return ['id' => ['required', 'string', 'uuid']];
    }

    public function entryId(): string
    {
        return strtolower($this->validated('id'));
    }
}
