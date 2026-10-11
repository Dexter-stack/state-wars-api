<?php

namespace App\Core;

use DateTimeImmutable;

/** West Africa Time (UTC+1, no daylight saving). All game days are WAT calendar days. Times are ms epoch. */
final class WatTime
{
    public const HOUR_MS = 3_600_000;

    public const DAY_MS = 24 * self::HOUR_MS;

    /** WAT calendar date ("YYYY-MM-DD") containing $ms. */
    public static function watDate(int $ms): string
    {
        return gmdate('Y-m-d', intdiv($ms + self::HOUR_MS - self::floorMod($ms + self::HOUR_MS, 1000), 1000));
    }

    /** Midnight WAT of a WAT date, as ms epoch. */
    public static function watMidnight(string $date): int
    {
        return (new DateTimeImmutable("{$date}T00:00:00+01:00"))->getTimestamp() * 1000;
    }

    public static function addDays(string $date, int $n): string
    {
        return self::watDate(self::watMidnight($date) + $n * self::DAY_MS + self::HOUR_MS);
    }

    /** 1 = Monday … 7 = Sunday, in WAT. */
    public static function watWeekday(int $ms): int
    {
        return (int) gmdate('N', intdiv($ms + self::HOUR_MS - self::floorMod($ms + self::HOUR_MS, 1000), 1000));
    }

    /** @return array{opensAt:int, closesAt:int} */
    public static function battleTimes(string $date, int $hourWat, int $windowMin): array
    {
        $opensAt = self::watMidnight($date) + $hourWat * self::HOUR_MS;

        return ['opensAt' => $opensAt, 'closesAt' => $opensAt + $windowMin * 60_000];
    }

    public static function dayNumber(string $date, string $launchDate): int
    {
        return max(1, JsMath::round((self::watMidnight($date) - self::watMidnight($launchDate)) / self::DAY_MS) + 1);
    }

    private static function floorMod(int $a, int $b): int
    {
        return (($a % $b) + $b) % $b;
    }
}
