<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\Channels\SmsChannel;

test('handles the sms channel', function () {
    expect((new SmsChannel)->channel())->toBe(NotificationChannel::Sms);
});

test('accepts E.164 phone numbers', function (string $recipient) {
    expect((new SmsChannel)->isValidRecipient($recipient))->toBeTrue();
})->with([
    'Turkish mobile number' => ['+905551234567'],
    'shortest number' => ['+12345678'],
    'longest number' => ['+123456789012345'],
]);

test('rejects recipients that are not E.164 phone numbers', function (string $recipient) {
    expect((new SmsChannel)->isValidRecipient($recipient))->toBeFalse();
})->with([
    'number without the plus sign' => ['905551234567'],
    'country code starting with zero' => ['+05551234567'],
    'number longer than 15 digits' => ['+1234567890123456'],
    'number with spaces' => ['+90 555 123 45 67'],
    'email address' => ['customer@example.com'],
]);

test('limits content to a single 160 character segment', function () {
    expect((new SmsChannel)->maxContentLength())->toBe(160);
});
