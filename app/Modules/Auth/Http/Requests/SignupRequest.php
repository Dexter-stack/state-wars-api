<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Core\Http\Rules\StrictBoolean;

/** The guest sign-up fields { username (trimmed), stateCode (upper-cased), remind = true } plus { email, code, password }. */
final class SignupRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

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
            'username' => ['present', 'string'],
            'stateCode' => ['present', 'string', 'size:2'],
            'remind' => [new StrictBoolean],
            'email' => CredentialRules::EMAIL,
            'code' => CredentialRules::CODE,
            'password' => CredentialRules::PASSWORD,
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
