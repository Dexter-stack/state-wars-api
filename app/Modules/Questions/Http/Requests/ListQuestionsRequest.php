<?php

namespace App\Modules\Questions\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Core\Http\Rules\StrictInteger;
use Illuminate\Validation\Rule;

/** zod ListQuery: status enum optional, subject optional, limit coerce int 1..100 = 50, offset coerce int ≥ 0 = 0 */
final class ListQuestionsRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->query->all();
    }

    protected function transform(array $data): array
    {
        return $data + ['limit' => 50, 'offset' => 0];
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in(['draft', 'approved', 'retired'])],
            'subject' => ['sometimes', 'string'],
            'limit' => [new StrictInteger(1, 100, coerce: true)],
            'offset' => [new StrictInteger(0, null, coerce: true)],
        ];
    }

    public function status(): ?string
    {
        return $this->validated('status');
    }

    public function subject(): ?string
    {
        return $this->validated('subject');
    }

    public function limit(): int
    {
        return StrictInteger::toInt($this->validated('limit'), true);
    }

    public function offset(): int
    {
        return StrictInteger::toInt($this->validated('offset'), true);
    }
}
