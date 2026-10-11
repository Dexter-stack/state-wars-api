<?php

namespace App\Core\Http;

use App\Core\Errors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for every request. Port of core/validate.ts: bad input becomes a 400 with field-level details.
 *
 * Input sources are explicit (like parse(Schema, req.body) / req.params / req.query in Node):
 * the JSON body and route parameters by default; query-string requests override source().
 * transform() applies the zod transforms (trim, toUpperCase, defaults) before the rules run.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is decided by the auth middleware and the services
    }

    /** @return array<string, mixed> */
    protected function source(): array
    {
        $body = $this->json()->all();

        return array_merge($body, $this->routeParams());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transform(array $data): array
    {
        return $data;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->transform($this->source());
    }

    /** @return array<string, mixed> */
    protected function routeParams(): array
    {
        return $this->route()?->parameters() ?? [];
    }

    protected function failedValidation(Validator $validator): void
    {
        $details = [];
        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => $field, 'message' => $message];
            }
        }

        throw Errors::badRequest('Some of the details sent are not valid.', $details);
    }

    /** zod .trim() / .toUpperCase(): only applied to strings, anything else is left for the rules to reject. */
    protected static function trimmed(mixed $v): mixed
    {
        return is_string($v) ? trim($v) : $v;
    }

    protected static function upper(mixed $v): mixed
    {
        return is_string($v) ? strtoupper($v) : $v;
    }
}
