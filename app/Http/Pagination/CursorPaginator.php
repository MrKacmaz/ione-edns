<?php

namespace App\Http\Pagination;

use DateTimeInterface;
use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;

/**
 * Writes dates into the cursor with their microseconds.
 *
 * Laravel turns a date into the cursor through its string form, which drops the
 * microseconds. The next page then starts from the whole second and skips every
 * row created later within that second, such as most notifications of a batch.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends BaseCursorPaginator<TKey, TValue>
 */
class CursorPaginator extends BaseCursorPaginator
{
    /**
     * The format the database compares the cursor's dates in.
     */
    public const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * Turn a cursor parameter into a value the cursor can encode.
     */
    protected function ensureParameterIsPrimitive($parameter): mixed
    {
        if ($parameter instanceof DateTimeInterface) {
            return $parameter->format(self::DATE_FORMAT);
        }

        return parent::ensureParameterIsPrimitive($parameter);
    }
}
