<?php

namespace App\Core\Clock;

use Illuminate\Support\Carbon;

/** Real time via Carbon, so Carbon::setTestNow() / $this->travelTo() control it in tests. */
final class CarbonClock implements Clock
{
    public function nowMs(): int
    {
        return (int) Carbon::now()->getTimestampMs();
    }
}
