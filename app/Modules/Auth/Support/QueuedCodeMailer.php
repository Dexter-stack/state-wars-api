<?php

namespace App\Modules\Auth\Support;

use App\Core\Clock\Clock;
use App\Modules\Auth\Jobs\SendCodeEmailJob;
use App\Modules\Auth\Support\Contracts\CodeMailer;
use Illuminate\Contracts\Bus\Dispatcher;

/** The CodeMailer the API uses: hands the email to the queue and returns at once. SendCodeEmailJob does the sending. */
final class QueuedCodeMailer implements CodeMailer
{
    public function __construct(private readonly Dispatcher $bus, private readonly Clock $clock) {}

    public function send(string $email, string $username, string $code, string $purpose, int $validMinutes): void
    {
        $this->bus->dispatch(new SendCodeEmailJob(
            $email, $username, $code, $purpose, $validMinutes,
            expiresAtMs: $this->clock->nowMs() + $validMinutes * 60_000,
        ));
    }
}
