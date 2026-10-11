<?php

namespace App\Modules\Questions\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Core\Http\Rules\StrictInteger;

/**
 * zod QuestionBody: subject string; text trim 10..300; highlight trim ≤40 optional; options = tuple of 4 strings (trim, 1..80);
 * answer int 0..3; explanation trim 5..300; funHeadline trim ≤80 optional; funBanter trim ≤120 optional.
 */
final class QuestionRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    protected function transform(array $data): array
    {
        foreach (['text', 'highlight', 'explanation', 'funHeadline', 'funBanter'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = self::trimmed($data[$k]);
            }
        }
        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = array_map(fn ($o) => self::trimmed($o), $data['options']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'subject' => ['present', 'string'],
            'text' => ['required', 'string', 'min:10', 'max:300'],
            'highlight' => ['sometimes', 'string', 'max:40'],
            'options' => ['required', 'array', 'list', 'size:4'],
            'options.*' => ['required', 'string', 'min:1', 'max:80'],
            'answer' => ['present', new StrictInteger(0, 3)],
            'explanation' => ['required', 'string', 'min:5', 'max:300'],
            'funHeadline' => ['sometimes', 'string', 'max:80'],
            'funBanter' => ['sometimes', 'string', 'max:120'],
        ];
    }

    /** @return array{subject: string, text: string, highlight?: string, options: list<string>, answer: int, explanation: string, funHeadline?: string, funBanter?: string} */
    public function question(): array
    {
        $v = $this->validated();
        $v['answer'] = StrictInteger::toInt($v['answer']);

        return $v;
    }
}
