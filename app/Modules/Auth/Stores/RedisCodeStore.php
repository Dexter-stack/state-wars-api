<?php

namespace App\Modules\Auth\Stores;

use App\Modules\Auth\Stores\Contracts\CodeStore;
use Illuminate\Redis\Connections\Connection;

/** code:{key} → { h: code hash, n: wrong tries }; hit:{key} → counter. Both expire on their own. */
final class RedisCodeStore implements CodeStore
{
    public function __construct(private readonly Connection $redis) {}

    public function put(string $key, string $codeHash, int $ttlSec): void
    {
        $k = "code:{$key}";
        $this->redis->transaction(function ($tx) use ($k, $codeHash, $ttlSec) {
            $tx->del($k);
            $tx->hset($k, 'h', $codeHash);
            $tx->expire($k, $ttlSec);
        });
    }

    public function check(string $key, string $codeHash, int $maxAttempts): string
    {
        $k = "code:{$key}";
        $stored = $this->redis->hget($k, 'h');
        if (! is_string($stored) || $stored === '') {
            return self::EXPIRED;
        }
        if (hash_equals($stored, $codeHash)) {
            // DEL is atomic: of two identical requests only one sees 1
            return (int) $this->redis->del($k) === 1 ? self::OK : self::EXPIRED;
        }
        if ((int) $this->redis->hincrby($k, 'n', 1) >= $maxAttempts) {
            $this->redis->del($k);
        }

        return self::WRONG;
    }

    public function hit(string $key, int $windowSec): int
    {
        $k = "hit:{$key}";
        $n = (int) $this->redis->incr($k);
        if ($n === 1) {
            $this->redis->expire($k, $windowSec);
        }

        return $n;
    }

    public function clear(string $key): void
    {
        $this->redis->del("hit:{$key}");
    }
}
