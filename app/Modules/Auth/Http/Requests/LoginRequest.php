<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** { identifier: username or email (trimmed), password }. The password is only checked for presence: no hints about its rules. */
final class LoginRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    protected function transform(array $data): array
    {
        if (array_key_exists('identifier', $data)) {
            $data['identifier'] = self::trimmed($data['identifier']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ];
    }

    public function identifier(): string
    {
        return $this->validated('identifier');
    }

    public function password(): string
    {
        return $this->validated('password');
    }
}
