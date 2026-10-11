<?php

namespace App\Modules\Questions\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod: params { date: YYYY-MM-DD } */
final class PrepareDayRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->routeParams();
    }

    public function rules(): array
    {
        return ['date' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/']];
    }

    public function battleDate(): string
    {
        return $this->validated('date');
    }
}
