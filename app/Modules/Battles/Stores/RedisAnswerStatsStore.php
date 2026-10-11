<?php

namespace App\Modules\Battles\Stores;

use App\Modules\Battles\Stores\Contracts\AnswerStatsStore;
use Illuminate\Redis\Connections\Connection;

/** rv:{battle}:{question} → canonical option (or "none") → count. Port of answer-stats.store.ts, same keys. */
final class RedisAnswerStatsStore implements AnswerStatsStore
{
    private const TTL_SEC = 14 * 24 * 3600;

    public function __construct(private readonly Connection $redis) {}

    public function record(string $battleId, string $questionId, ?int $canonicalChoice): void
    {
        $key = "rv:{$battleId}:{$questionId}";
        $this->redis->transaction(function ($tx) use ($key, $canonicalChoice) {
            $tx->hincrby($key, $canonicalChoice === null ? 'none' : (string) $canonicalChoice, 1);
            $tx->expire($key, self::TTL_SEC);
        });
    }

    public function distribution(string $battleId, string $questionId): array
    {
        $h = $this->redis->hgetall("rv:{$battleId}:{$questionId}") ?: [];

        return ['counts' => array_map(fn ($i) => (int) ($h[(string) $i] ?? $h[$i] ?? 0), [0, 1, 2, 3]), 'none' => (int) ($h['none'] ?? 0)];
    }
}
