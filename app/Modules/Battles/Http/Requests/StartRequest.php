<?php

namespace App\Modules\Battles\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Modules\Catalog\Catalog;
use Illuminate\Validation\Rule;

/** zod: z.object({ league: z.enum(leagueIds).nullable().optional() }).default({}) */
final class StartRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return ['league' => ['sometimes', 'nullable', 'string', Rule::in(Catalog::leagueIds())]];
    }

    public function league(): ?string
    {
        return $this->validated('league') ?: null;
    }
}
