<?php

namespace App\Modules\Auth\Support\Contracts;

/** Sends a one-time code to an email address. The only way a code ever leaves the server. */
interface CodeMailer
{
    public const SIGNUP = 'signup';

    public const SECURE = 'secure';

    public const RESET = 'reset';

    /**
     * @param  string  $username  empty for SIGNUP, when the account doesn't exist yet
     * @param  self::SIGNUP|self::SECURE|self::RESET  $purpose
     */
    public function send(string $email, string $username, string $code, string $purpose, int $validMinutes): void;
}
