<?php

namespace App\Modules\Auth\Http\Requests;

/** The field rules shared by the sign-in and recovery requests, so every endpoint accepts exactly the same values. */
final class CredentialRules
{
    /** Trimmed and lower-cased by the request before these run. */
    public const EMAIL = ['required', 'string', 'email', 'max:254'];

    /** Never trimmed: spaces are part of a password. 72 is bcrypt's limit. */
    public const PASSWORD = ['required', 'string', 'min:8', 'max:72'];

    public const CODE = ['required', 'string', 'regex:/^\d{6}$/'];

    public static function email(mixed $v): mixed
    {
        return is_string($v) ? mb_strtolower(trim($v)) : $v;
    }
}
