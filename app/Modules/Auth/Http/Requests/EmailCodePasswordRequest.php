<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** { email, code: 6 digits, password: 8..72 }. Used to confirm a new account email and to reset a password. */
final class EmailCodePasswordRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    protected function transform(array $data): array
    {
        if (array_key_exists('email', $data)) {
            $data['email'] = CredentialRules::email($data['email']);
        }
        if (array_key_exists('code', $data)) {
            $data['code'] = self::trimmed($data['code']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'email' => CredentialRules::EMAIL,
            'code' => CredentialRules::CODE,
            'password' => CredentialRules::PASSWORD,
        ];
    }

    public function email(): string
    {
        return $this->validated('email');
    }

    public function code(): string
    {
        return $this->validated('code');
    }

    public function password(): string
    {
        return $this->validated('password');
    }
}
