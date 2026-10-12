<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** { email } trimmed and lower-cased. */
final class EmailRequest extends ApiFormRequest
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

        return $data;
    }

    public function rules(): array
    {
        return ['email' => CredentialRules::EMAIL];
    }

    public function email(): string
    {
        return $this->validated('email');
    }
}
