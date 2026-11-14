<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a pagination cursor taken from a client.
 *
 * The cursor must decode, and the values it carries must fit the columns the
 * listing is ordered by. A tampered cursor then gets a 422 instead of reaching
 * the database with values the columns cannot hold.
 */
class PaginationCursorRule implements ValidationRule
{
    /**
     * @param  array<string, list<string|ValidationRule>>  $parameterRules  The rules per ordering column.
     */
    public function __construct(private readonly array $parameterRules) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cursor = Cursor::fromEncoded($value);

        if ($cursor === null || Validator::make($cursor->toArray(), $this->parameterRules)->fails()) {
            $fail('validation.pagination_cursor')->translate();
        }
    }
}
