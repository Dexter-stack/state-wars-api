<?php

namespace App\Modules\Leaderboard\Stores\Contracts;

/**
 * Live per-state totals for a running battle, kept in Redis.
 * Each finished entry is 3 hash increments (sum, count, score histogram): O(1), no row locks.
 */
interface LiveScoreStore
{
    public function add(string $battleId, string $stateCode, float|int $weightedScore, int $rawScore): void;

    /** @return array<string, array{sum: float|int, count: int}> by state code */
    public function totals(string $battleId): array;

    /** @return array<int, int> score => players */
    public function histogram(string $battleId): array;
}
