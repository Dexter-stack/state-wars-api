<?php

namespace App\Core\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * z.number().int().min().max(): a JSON number with no fraction (3 and 3.0 pass, "3" does not).
 * With $coerce, numeric strings pass too (z.coerce.number(), used for query strings and path params).
 */
final class StrictInteger implements ValidationRule
{
    public function __construct(private readonly ?int $min = null, private readonly ?int $max = null, private readonly bool $coerce = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $n = self::toInt($value, $this->coerce);
        if ($n === null) {
            $fail('Expected integer.');

            return;
        }
        if ($this->min !== null && $n < $this->min) {
            $fail("Number must be greater than or equal to {$this->min}.");
        }
        if ($this->max !== null && $n > $this->max) {
            $fail("Number must be less than or equal to {$this->max}.");
        }
    }

    public static function toInt(mixed $value, bool $coerce = false): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }
        if ($coerce && is_string($value) && is_numeric(trim($value))) {
            $f = (float) trim($value);

            return floor($f) === $f ? (int) $f : null;
        }

        return null;
    }
}
