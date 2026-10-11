<?php

namespace App\Core\Cache;

use Closure;

/**
 * Read-through cache for hot, shared data (today's battle, the state table, sessions).
 * Values must be JSON-serialisable (arrays and scalars, null included): they are shared through Redis.
 */
interface Cache
{
    /**
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public function getOrSet(string $key, int $ttlSec, Closure $load, ?int $localTtlMs = null): mixed;

    public function del(string $key): void;
}
