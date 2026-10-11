<?php

namespace App\Modules\Battles\Support;

use App\Core\JsMath;
use App\Core\Random;

/** Pure game rules: no I/O, fully unit-tested. Port of scoring.ts. */
final class Scoring
{
    public const BASE_POINTS = 100;

    public const MAX_SPEED_BONUS = 50;

    /** @return array{inTime: bool, correct: bool, points: int} */
    public static function scoreAnswer(?int $choice, int $answer, int $elapsedMs, int $limitMs, int $graceMs): array
    {
        $inTime = $elapsedMs <= $limitMs + $graceMs;
        $correct = $inTime && $choice !== null && $choice === $answer;
        $speed = max(0, 1 - min($elapsedMs, $limitMs) / $limitMs);
        $points = $correct ? self::BASE_POINTS + JsMath::round(self::MAX_SPEED_BONUS * $speed) : 0;

        return ['inTime' => $inTime, 'correct' => $correct, 'points' => $points];
    }

    /** Order in which this player sees the battle's questions. @return list<int> */
    public static function questionOrder(int $seed, int $count): array
    {
        return Random::permutation($count, $seed);
    }

    /** Order in which this player sees the options of their $index-th question. display[i] = canonical option. @return list<int> */
    public static function optionOrder(int $seed, int $index): array
    {
        return Random::permutation(4, ($seed + ($index + 1) * 7919) & 0xFFFFFFFF);
    }

    /** @param list<int> $order */
    public static function toCanonical(array $order, int $display): int
    {
        return $order[$display];
    }

    /** @param list<int> $order */
    public static function toDisplay(array $order, int $canonical): int
    {
        $i = array_search($canonical, $order, true);

        return $i === false ? -1 : $i;
    }
}
