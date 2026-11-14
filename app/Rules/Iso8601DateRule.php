<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Accepts only the ISO 8601 forms of a date or date-time.
 *
 * Laravel's date rule takes anything PHP can parse, including relative phrases
 * such as "tomorrow" or "next monday" whose meaning depends on when the request
 * arrives. Combined with the date rule, which checks the calendar values, this
 * leaves a date (2026-11-14), or a date-time with optional seconds, fraction and
 * offset (2026-11-14T09:30:00Z, 2026-11-14T12:30:00.250+03:00). A date-time
 * without an offset is read as UTC.
 */
class Iso8601DateRule implements ValidationRule
{
    private const string PATTERN = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})?)?$/';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('validation.iso8601_date')->translate();
        }
    }
}
