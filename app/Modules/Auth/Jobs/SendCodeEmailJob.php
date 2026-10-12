<?php

namespace App\Modules\Auth\Jobs;

use App\Core\Clock\Clock;
use App\Modules\Auth\Support\LaravelCodeMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emails one sign-up, sign-in or reset code, off the request: a slow mail provider never holds an API worker.
 *
 * - Encrypted (ShouldBeEncrypted): the code is in the payload, so neither Redis nor failed_jobs holds it in plain text.
 * - Runs on its own "mail" queue, so a long sweep on "default" never delays a code a player is waiting for.
 * - Retried up to four times after a failure (mail servers and networks drop connections), but never once the code has expired: a late email with a dead code only confuses.
 */
final class SendCodeEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'mail';

    public int $tries = 5;

    public int $timeout = 20;

    public function __construct(
        public readonly string $email,
        public readonly string $username,
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $validMinutes,
        public readonly int $expiresAtMs,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** @return list<int> seconds before each retry. With the 10s mail timeout, five tries end well inside the code's 10 minutes. */
    public function backoff(): array
    {
        return [3, 5, 10, 20];
    }

    public function handle(LaravelCodeMailer $mailer, Clock $clock): void
    {
        if ($clock->nowMs() >= $this->expiresAtMs) {
            Log::warning('code email dropped: the code expired before it could be sent', ['purpose' => $this->purpose]);

            return;
        }
        $mailer->send($this->email, $this->username, $this->code, $this->purpose, $this->validMinutes);
    }

    /** After the last try. The player was told a code is on its way; they can ask for another. Never logs the code or address. */
    public function failed(?Throwable $e): void
    {
        Log::error('code email failed', ['purpose' => $this->purpose, 'error' => $e?->getMessage()]);
    }
}
