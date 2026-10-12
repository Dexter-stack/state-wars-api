<?php

namespace Tests\Support;

use App\Modules\Auth\Support\Contracts\CodeMailer;

/** Keeps the codes instead of emailing them, so tests can read what a player would receive. */
final class RecordingCodeMailer implements CodeMailer
{
    /** @var list<array{email: string, username: string, code: string, purpose: string, validMinutes: int}> */
    public array $sent = [];

    public function send(string $email, string $username, string $code, string $purpose, int $validMinutes): void
    {
        $this->sent[] = compact('email', 'username', 'code', 'purpose', 'validMinutes');
    }

    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'];
    }
}
