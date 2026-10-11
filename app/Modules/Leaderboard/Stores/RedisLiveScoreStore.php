<?php

namespace App\Modules\Leaderboard\Stores;

use App\Modules\Leaderboard\Stores\Contracts\LiveScoreStore;
use Illuminate\Redis\Connections\Connection;

/**
 * Live per-state totals for a running battle (port of live-score.store.ts, same keys):
 *   lb:{battle}:sum  state → weighted score sum (HINCRBYFLOAT)
 *   lb:{battle}:cnt  state → players            (HINCRBY)
 *   lb:{battle}:hist score → players            (HINCRBY)
 * One MULTI per finished entry: O(1), no row locks, so 50,000 players finishing within minutes is fine.
 */
final class RedisLiveScoreStore implements LiveScoreStore
{
    private const TTL_SEC = 7 * 24 * 3600;

    public function __construct(private readonly Connection $redis) {}

    public function add(string $battleId, string $stateCode, float|int $weightedScore, int $rawScore): void
    {
        $k = "lb:{$battleId}";
        $this->redis->transaction(function ($tx) use ($k, $stateCode, $weightedScore, $rawScore) {
            $tx->hincrbyfloat("{$k}:sum", $stateCode, $weightedScore);
            $tx->hincrby("{$k}:cnt", $stateCode, 1);
            $tx->hincrby("{$k}:hist", (string) $rawScore, 1);
            $tx->expire("{$k}:sum", self::TTL_SEC);
            $tx->expire("{$k}:cnt", self::TTL_SEC);
            $tx->expire("{$k}:hist", self::TTL_SEC);
        });
    }

    public function totals(string $battleId): array
    {
        $sum = $this->redis->hgetall("lb:{$battleId}:sum") ?: [];
        $cnt = $this->redis->hgetall("lb:{$battleId}:cnt") ?: [];
        $out = [];
        foreach ($cnt as $state => $c) {
            $out[(string) $state] = ['sum' => isset($sum[$state]) ? $sum[$state] + 0 : 0, 'count' => (int) $c];
        }

        return $out;
    }

    public function histogram(string $battleId): array
    {
        $out = [];
        foreach ($this->redis->hgetall("lb:{$battleId}:hist") ?: [] as $score => $c) {
            $out[(int) $score] = (int) $c;
        }

        return $out;
    }
}
