<?php

namespace App\Core;

/**
 * Number formatting with JavaScript semantics, so responses match the Node API digit for digit.
 * PHP's round() rounds halves away from zero (round(-2.5) = -3); JS Math.round rounds them up (-2).
 */
final class JsMath
{
    /** Math.round */
    public static function round(float|int $x): int
    {
        return (int) floor($x + 0.5);
    }

    /** Math.round(x * 100) / 100 */
    public static function round2(float|int $x): float
    {
        return self::round($x * 100) / 100;
    }

    /** Math.round(x * 10) / 10 */
    public static function round1(float|int $x): float
    {
        return self::round($x * 10) / 10;
    }

    /**
     * Number.prototype.toFixed: rounds the exact binary value, ties away from zero.
     * (number_format/sprintf pre-round or round half-even, which differs on values like 6.25.)
     */
    public static function toFixed(float|int $x, int $digits): string
    {
        $neg = $x < 0;
        $exact = sprintf('%.40F', abs((float) $x)); // exact decimal expansion of the double (to 40 places)
        [$int, $frac] = explode('.', $exact);
        $keep = $int.substr($frac, 0, $digits);
        $roundUp = (int) ($frac[$digits] ?? '0') >= 5;
        if ($roundUp) {
            $keep = self::incrementDigits($keep);
        }
        $intPart = substr($keep, 0, strlen($keep) - $digits);
        $fracPart = $digits > 0 ? substr($keep, -$digits) : '';
        $intPart = ltrim($intPart, '0');
        $intPart = $intPart === '' ? '0' : $intPart;
        $out = $digits > 0 ? "{$intPart}.{$fracPart}" : $intPart;
        $isZero = trim(str_replace('.', '', $out), '0') === '';

        return ($neg && ! $isZero ? '-' : '').$out;
    }

    /** Number → JSON-style string the way JS prints it in template literals (5 not 5.0). */
    public static function str(float|int $x): string
    {
        if (is_float($x) && floor($x) === $x && abs($x) < 1e15) {
            return (string) (int) $x;
        }

        return (string) $x;
    }

    private static function incrementDigits(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0) {
            if ($digits[$i] === '9') {
                $digits[$i] = '0';
                $i--;

                continue;
            }
            $digits[$i] = (string) ((int) $digits[$i] + 1);

            return $digits;
        }

        return '1'.$digits;
    }
}
