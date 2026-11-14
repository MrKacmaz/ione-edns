<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\Channels\EmailChannel;

test('handles the email channel', function () {
    expect((new EmailChannel)->channel())->toBe(NotificationChannel::Email);
});

test('accepts email addresses', function () {
    expect((new EmailChannel)->isValidRecipient('customer@example.com'))->toBeTrue();
});

test('rejects recipients that are not email addresses', function (string $recipient) {
    expect((new EmailChannel)->isValidRecipient($recipient))->toBeFalse();
})->with([
    'address without a domain' => ['customer@'],
    'phone number' => ['+905551234567'],
]);

test('limits content to 10000 characters', function () {
    expect((new EmailChannel)->maxContentLength())->toBe(10_000);
});
