<?php

namespace App\Core\Clock;

/** Injected everywhere time matters, so tests can control it. */
interface Clock
{
    /** Milliseconds since the Unix epoch. */
    public function nowMs(): int;
}
