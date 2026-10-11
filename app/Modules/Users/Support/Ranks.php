<?php

namespace App\Modules\Users\Support;

/**
 * Points needed for each rank. PLACEHOLDER values until the team decides.
 * A strong player earns roughly 1,000-1,400 points per battle (10 x 100 + speed bonus).
 */
final class Ranks
{
    public const THRESHOLDS = [
        ['rank' => 'Olodo', 'min' => 0],
        ['rank' => 'Jambite', 'min' => 2_000],
        ['rank' => 'Average Joe', 'min' => 8_000],
        ['rank' => 'Efiko', 'min' => 25_000],
        ['rank' => 'Professor', 'min' => 60_000],
    ];

    /** @return array{rank: string, pointsToNext: int} */
    public static function rankFor(int $points): array
    {
        $t = self::THRESHOLDS;
        $i = 0;
        while ($i + 1 < count($t) && $points >= $t[$i + 1]['min']) {
            $i++;
        }
        $next = $t[$i + 1] ?? null;

        return ['rank' => $t[$i]['rank'], 'pointsToNext' => $next ? $next['min'] - $points : 0];
    }
}
