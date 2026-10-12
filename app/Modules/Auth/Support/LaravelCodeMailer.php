<?php

namespace App\Modules\Auth\Support;

use App\Modules\Auth\Support\Contracts\CodeMailer;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;

/**
 * Plain-text mail through whichever mailer MAIL_MAILER names ("log" locally: emails go to storage/logs/mail.log).
 * Called by SendCodeEmailJob on a queue worker, not during the request.
 */
final class LaravelCodeMailer implements CodeMailer
{
    public function __construct(private readonly Mailer $mailer) {}

    public function send(string $email, string $username, string $code, string $purpose, int $validMinutes): void
    {
        [$subject, $why] = match ($purpose) {
            self::RESET => ["{$code} is your State Wars password reset code", 'reset your password'],
            self::SIGNUP => ["{$code} is your State Wars sign-up code", 'create your account'],
            default => ["{$code} is your State Wars code", 'secure your account'],
        };
        $hello = $username === '' ? 'Hi,' : "Hi @{$username},";

        $body = "{$hello}\n\nUse this code to {$why}:\n\n    {$code}\n\n"
            ."It works for {$validMinutes} minutes. If you didn't ask for it, ignore this email: nothing has changed.\n\nState Wars";

        $this->mailer->raw($body, fn (Message $m) => $m->to($email)->subject($subject));
    }
}
