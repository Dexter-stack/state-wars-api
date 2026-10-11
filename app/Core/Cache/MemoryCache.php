<?php

namespace App\Core\Cache;

use Closure;

/** For unit tests: same contract, memory only, no expiry. */
final class MemoryCache implements Cache
{
    /** @var array<string, mixed> */
    private array $m = [];

    public function getOrSet(string $key, int $ttlSec, Closure $load, ?int $localTtlMs = null): mixed
    {
        if (! array_key_exists($key, $this->m)) {
            $this->m[$key] = $load();
        }

        return $this->m[$key];
    }

    public function del(string $key): void
    {
        unset($this->m[$key]);
    }
}
