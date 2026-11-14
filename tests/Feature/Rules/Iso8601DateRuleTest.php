<?php

use App\Rules\Iso8601DateRule;
use Illuminate\Support\Facades\Validator;

test('accepts a date or date-time in an ISO 8601 form', function (string $value) {
    expect(Validator::make(['date' => $value], ['date' => [new Iso8601DateRule]])->passes())->toBeTrue();
})->with([
    'a date' => ['2026-11-14'],
    'a date-time in UTC' => ['2026-11-14T09:30:00Z'],
    'a date-time with an offset' => ['2026-11-14T12:30:00+03:00'],
    'a date-time with a fraction' => ['2026-11-14T09:30:00.250000Z'],
    'a date-time without seconds' => ['2026-11-14T09:30Z'],
    'a date-time without an offset' => ['2026-11-14T09:30:00'],
]);

test('rejects any other date form, such as a relative phrase', function (mixed $value) {
    $validator = Validator::make(['scheduled_at' => $value], ['scheduled_at' => [new Iso8601DateRule]]);

    expect($validator->errors()->first('scheduled_at'))
        ->toBe('The scheduled at must be an ISO 8601 date or date-time, such as 2026-11-14 or 2026-11-14T09:30:00Z.');
})->with([
    'a relative phrase' => ['tomorrow'],
    'a relative offset' => ['+1 day'],
    'a written date' => ['14 November 2026'],
    'a date with slashes' => ['2026/11/14'],
    'a date-time with a space' => ['2026-11-14 09:30:00'],
    'a timestamp' => ['1794643200'],
    'not a string' => [[2026, 11, 14]],
]);
