<?php

use App\Rules\PaginationCursorRule;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Validator;

/**
 * Validate a cursor against the ordering columns of the notification listing.
 */
function validateNotificationCursor(string $cursor): Illuminate\Validation\Validator
{
    return Validator::make(['cursor' => $cursor], [
        'cursor' => [new PaginationCursorRule(['created_at' => ['required', 'date'], 'id' => ['required', 'uuid']])],
    ]);
}

test('accepts a cursor whose values fit the ordering columns', function () {
    $cursor = new Cursor(['created_at' => '2026-11-14T10:00:00.250000Z', 'id' => '01a1d749-012a-715a-b479-92346539f571']);

    expect(validateNotificationCursor($cursor->encode())->passes())->toBeTrue();
});

test('rejects a cursor that was made up or tampered with', function (string $cursor) {
    $validator = validateNotificationCursor($cursor);

    expect($validator->errors()->first('cursor'))->toBe('The cursor is invalid. Use the cursor from the links of the previous page.');
})->with([
    'not an encoded cursor' => ['not-a-cursor'],
    'a column missing' => [(new Cursor(['id' => '01a1d749-012a-715a-b479-92346539f571']))->encode()],
    'a date column holding text' => [(new Cursor(['created_at' => 'not-a-date', 'id' => '01a1d749-012a-715a-b479-92346539f571']))->encode()],
    'an id that is not a uuid' => [(new Cursor(['created_at' => '2026-11-14T10:00:00Z', 'id' => '42']))->encode()],
]);
