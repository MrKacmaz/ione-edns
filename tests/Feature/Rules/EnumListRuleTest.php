<?php

use App\Enums\NotificationStatus;
use App\Rules\EnumListRule;
use Illuminate\Support\Facades\Validator;

test('accepts one or more known values, comma separated or as a list', function (mixed $status) {
    $validator = Validator::make(['status' => $status], ['status' => [new EnumListRule(NotificationStatus::class)]]);

    expect($validator->passes())->toBeTrue();
})->with([
    'one value' => ['failed'],
    'comma separated' => ['failed,queued'],
    'with spaces' => [' failed , queued '],
    'a list' => [['failed', 'queued']],
]);

test('rejects the whole filter under its own name when any value is unknown or missing', function (mixed $status) {
    $validator = Validator::make(['status' => $status], ['status' => [new EnumListRule(NotificationStatus::class)]]);

    expect($validator->errors()->toArray())->toBe(['status' => ['The selected status is invalid.']]);
})->with([
    'unknown value' => ['delivered'],
    'one unknown among known ones' => ['failed,delivered'],
    'nothing but commas' => [','],
    'a list with a non-text value' => [['failed', ['queued']]],
    'a keyed array' => [['first' => 'failed']],
]);

test('splits the input into its distinct values', function () {
    expect(EnumListRule::values('failed, queued,failed,'))->toBe(['failed', 'queued'])
        ->and(EnumListRule::values(['sent']))->toBe(['sent'])
        ->and(EnumListRule::values(42))->toBeNull();
});
