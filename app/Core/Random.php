<?php

namespace App\Core;

use Closure;

/**
 * Deterministic shuffles from a per-entry seed. The same seed always gives the same order,
 * so the server never has to store each player's question and option order.
 *
 * Exact port of the Node core/random.ts (mulberry32). Every intermediate value is kept as an
 * unsigned 32-bit pattern; JS ToInt32/ToUint32 conversions are all "mod 2^32", so masking gives
 * identical bits. Checked against vectors generated from the Node source (tests/fixtures).
 */
final class Random
{
    private const MASK = 0xFFFFFFFF;

    /** mulberry32: small, fast, good enough for shuffling (not for security). */
    public static function prng(int $seed): Closure
    {
        $a = $seed & self::MASK;

        return static function () use (&$a): float {
            $a = ($a + 0x6D2B79F5) & self::MASK;
            $t = $a;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $t = ($t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & self::MASK)) & self::MASK;

            return (($t ^ ($t >> 14)) & self::MASK) / 4294967296;
        };
    }

    /** @return list<int> */
    public static function permutation(int $n, int $seed): array
    {
        $rand = self::prng($seed);
        $out = range(0, max(0, $n - 1));
        if ($n <= 0) {
            return [];
        }
        for ($i = $n - 1; $i > 0; $i--) {
            $j = (int) floor($rand() * ($i + 1));
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }

        return $out;
    }

    public static function randomSeed(): int
    {
        return random_int(0, 2 ** 31 - 1);
    }

    /** Math.imul on unsigned 32-bit patterns, without overflowing PHP's 64-bit int. */
    private static function imul(int $a, int $b): int
    {
        $a &= self::MASK;
        $b &= self::MASK;
        $lo = ($a & 0xFFFF) * $b;
        $hi = ((($a >> 16) * $b) & 0xFFFF) << 16;

        return ($lo + $hi) & self::MASK;
    }
}
