<?php

namespace App\Core\Cache;

use Closure;
use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Two-level read-through cache for hot, shared data.
 *  1. Per-worker memory: answers most requests with no network hop. Bounded (LRU) and short-lived.
 *  2. Redis: shared between workers and servers, so the database is hit once per TTL, not once per worker.
 * Single-flight: concurrent misses for the same key wait on one Redis lock instead of stampeding the database at 8pm.
 *
 * Octane: this object is a container singleton that lives as long as the worker, on purpose (it is the
 * in-process cache). It holds no request data, only shared values with a TTL.
 */
final class TwoLevelCache implements Cache
{
    private const MAX_LOCAL = 5_000;

    /** @var array<string, array{0: float, 1: mixed}> key => [expiresAtMs, value], oldest first */
    private array $local = [];

    public function __construct(private readonly Connection $redis, private readonly string $prefix = 'c:') {}

    public function getOrSet(string $key, int $ttlSec, Closure $load, ?int $localTtlMs = null): mixed
    {
        $localTtl = $localTtlMs ?? min($ttlSec * 1000, 2_000);

        $hit = $this->local[$key] ?? null;
        if ($hit !== null && $hit[0] > self::nowMs()) {
            return $hit[1];
        }

        [$found, $v] = $this->readRedis($key);
        if ($found) {
            $this->setLocal($key, $v, $localTtl);

            return $v;
        }

        $lock = new RedisLock($this->redis, $this->prefix.'sf:'.$key, 10);
        $locked = false;
        try {
            try {
                $locked = (bool) $lock->block(3);
            } catch (LockTimeoutException) {
                $locked = false; // the other loader is slow: load ourselves rather than fail
            } catch (Throwable) {
                $locked = false; // Redis down: still serve from the database
            }
            if ($locked) {
                [$found, $v] = $this->readRedis($key); // someone else loaded it while we waited
                if ($found) {
                    $this->setLocal($key, $v, $localTtl);

                    return $v;
                }
            }

            $v = $load();
            $this->setLocal($key, $v, $localTtl);
            try {
                $this->redis->setex($this->prefix.$key, $ttlSec, json_encode(['v' => $v], JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                // Redis down: the value is still returned
            }

            return $v;
        } finally {
            if ($locked) {
                try {
                    $lock->release();
                } catch (Throwable) {
                }
            }
        }
    }

    public function del(string $key): void
    {
        unset($this->local[$key]);
        try {
            $this->redis->del($this->prefix.$key);
        } catch (Throwable) {
        }
    }

    /** Test helper: forget the in-process level. */
    public function flushLocal(): void
    {
        $this->local = [];
    }

    /** @return array{0: bool, 1: mixed} */
    private function readRedis(string $key): array
    {
        try {
            $raw = $this->redis->get($this->prefix.$key);
        } catch (Throwable) {
            return [false, null];
        }
        if ($raw === null || $raw === false) {
            return [false, null];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_key_exists('v', $decoded) ? [true, $decoded['v']] : [false, null];
    }

    private function setLocal(string $key, mixed $v, int $ttlMs): void
    {
        unset($this->local[$key]); // re-insert at the end: most recent
        $this->local[$key] = [self::nowMs() + $ttlMs, $v];
        if (count($this->local) > self::MAX_LOCAL) {
            unset($this->local[array_key_first($this->local)]);
        }
    }

    /** Monotonic, not the game clock: local TTLs are about real elapsed time. */
    private static function nowMs(): float
    {
        return hrtime(true) / 1e6;
    }
}
