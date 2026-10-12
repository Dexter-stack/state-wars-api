<?php

namespace App\Modules\Auth\Stores\Contracts;

/** Short-lived email codes and the small counters that limit guessing. Only a hash of each code is kept. */
interface CodeStore
{
    public const OK = 'ok';

    public const WRONG = 'wrong';

    public const EXPIRED = 'expired';

    /** Stores the code for $key, replacing any earlier one. */
    public function put(string $key, string $codeHash, int $ttlSec): void;

    /**
     * Checks a code. OK consumes it (exactly one caller wins). After $maxAttempts wrong tries the code is
     * destroyed and every later check is EXPIRED.
     *
     * @return self::OK|self::WRONG|self::EXPIRED
     */
    public function check(string $key, string $codeHash, int $maxAttempts): string;

    /** Counts an event in a fixed window and returns the count so far, this one included. */
    public function hit(string $key, int $windowSec): int;

    public function clear(string $key): void;
}
