<?php

namespace App\Modules\Users\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** zod: { username: z.string().trim().max(32) } */
final class CheckUsernameRequest extends ApiFormRequest
{
    protected function transform(array $data): array
    {
        if (array_key_exists('username', $data)) {
            $data['username'] = self::trimmed($data['username']);
        }

        return $data;
    }

    public function rules(): array
    {
        return ['username' => ['present', 'string', 'max:32']];
    }

    public function username(): string
    {
        return $this->validated('username');
    }
}
