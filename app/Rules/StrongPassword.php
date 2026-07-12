<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FR-A6: at least 12 characters with upper- and lowercase letters,
 * a digit and a symbol.
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;

        if (strlen($value) < 12) {
            $fail('The :attribute must be at least 12 characters.');
        }
        if (! preg_match('/[a-z]/', $value) || ! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must contain both upper- and lowercase letters.');
        }
        if (! preg_match('/\d/', $value)) {
            $fail('The :attribute must contain at least one number.');
        }
        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must contain at least one symbol.');
        }
    }
}
