<?php

namespace App\Modules\Battles\Http\Requests;

use App\Core\Http\Rules\StrictInteger;

/** zod RevealParams: { id: z.string().uuid(), n: z.coerce.number().int().min(0).max(19) } */
final class RevealRequest extends EntryIdRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'uuid'],
            'n' => ['required', new StrictInteger(0, 19, coerce: true)],
        ];
    }

    public function n(): int
    {
        return StrictInteger::toInt($this->validated('n'), true);
    }
}
