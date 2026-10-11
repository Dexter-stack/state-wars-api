<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Core\Http\Rules\StrictBoolean;

/** zod: { username: z.string().trim(), stateCode: z.string().length(2).toUpperCase(), remind: z.boolean().default(true) } */
final class GuestRequest extends ApiFormRequest
{
    protected function transform(array $data): array
    {
        if (array_key_exists('username', $data)) {
            $data['username'] = self::trimmed($data['username']);
        }
        if (array_key_exists('stateCode', $data)) {
            $data['stateCode'] = self::upper($data['stateCode']);
        }
        if (! array_key_exists('remind', $data)) {
            $data['remind'] = true;
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'username' => ['present', 'string'],
            'stateCode' => ['present', 'string', 'size:2'],
            'remind' => [new StrictBoolean],
        ];
    }

    public function username(): string
    {
        return $this->validated('username');
    }

    public function stateCode(): string
    {
        return $this->validated('stateCode');
    }

    public function remind(): bool
    {
        return $this->validated('remind');
    }
}
