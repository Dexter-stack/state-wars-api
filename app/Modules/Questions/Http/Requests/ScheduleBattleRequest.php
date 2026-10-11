<?php

namespace App\Modules\Questions\Http\Requests;

use App\Core\Http\ApiFormRequest;
use Illuminate\Validation\Rule;

/** zod ScheduleBody: date YYYY-MM-DD, kind daily|league, subject string|null = null, questionIds uuid[1..20] optional */
final class ScheduleBattleRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    protected function transform(array $data): array
    {
        return $data + ['subject' => null];
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'kind' => ['required', 'string', Rule::in(['daily', 'league'])],
            'subject' => ['present', 'nullable', 'string'],
            'questionIds' => ['sometimes', 'array', 'list', 'min:1', 'max:20'],
            'questionIds.*' => ['string', 'uuid'],
        ];
    }

    public function battleDate(): string
    {
        return $this->validated('date');
    }

    public function kind(): string
    {
        return $this->validated('kind');
    }

    public function subject(): ?string
    {
        return $this->validated('subject');
    }

    /** @return list<string>|null */
    public function questionIds(): ?array
    {
        $ids = $this->validated('questionIds');

        return $ids === null ? null : array_map('strtolower', $ids);
    }
}
