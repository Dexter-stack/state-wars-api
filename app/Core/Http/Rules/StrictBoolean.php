<?php

namespace App\Core\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** z.boolean(): only JSON true/false (Laravel's "boolean" also accepts 1, "0", "true"…). */
final class StrictBoolean implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail('Expected boolean.');
        }
    }
}
