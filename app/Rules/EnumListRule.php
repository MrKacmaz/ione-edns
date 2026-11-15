<?php

namespace App\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A filter that takes one or more values of a string-backed enum.
 *
 * The values come either comma separated (`status=failed,queued`) or as a list
 * (`status[]=failed&status[]=queued`); a single value works as before. Any value
 * the enum does not know rejects the whole filter, under the filter's own name.
 */
class EnumListRule implements ValidationRule
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private readonly string $enum) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $values = self::values($value);

        if ($values === null || $values === [] || array_any($values, fn (string $value): bool => $this->enum::tryFrom($value) === null)) {
            $fail('validation.enum')->translate();
        }
    }

    /**
     * Split the input into its distinct values, or null when it is neither text nor a list of texts.
     *
     * @return list<string>|null
     */
    public static function values(mixed $input): ?array
    {
        if (is_string($input)) {
            $input = explode(',', $input);
        }

        if (! is_array($input) || ! array_is_list($input) || array_any($input, fn (mixed $value): bool => ! is_string($value))) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(trim(...), $input), fn (string $value): bool => $value !== '')));
    }
}
