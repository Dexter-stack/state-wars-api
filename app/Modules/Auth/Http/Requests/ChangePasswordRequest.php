<?php

namespace App\Modules\Auth\Http\Requests;

use App\Core\Http\ApiFormRequest;

/** { currentPassword, newPassword: 8..72 } */
final class ChangePasswordRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->json()->all();
    }

    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string', 'max:72'],
            'newPassword' => CredentialRules::PASSWORD,
        ];
    }

    public function currentPassword(): string
    {
        return $this->validated('currentPassword');
    }

    public function newPassword(): string
    {
        return $this->validated('newPassword');
    }
}
