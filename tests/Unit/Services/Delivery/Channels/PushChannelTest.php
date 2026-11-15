<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\Channels\PushChannel;

test('handles the push channel', function () {
    expect((new PushChannel)->channel())->toBe(NotificationChannel::Push);
});

test('accepts device tokens', function (string $recipient) {
    expect((new PushChannel)->isValidRecipient($recipient))->toBeTrue();
})->with([
    'token with separators' => ['fcm:APA91bH_k3-x.9Q2'],
    'token of 512 characters' => [str_repeat('a', 512)],
]);

test('rejects recipients that are not device tokens', function (string $recipient) {
    expect((new PushChannel)->isValidRecipient($recipient))->toBeFalse();
})->with([
    'token shorter than 16 characters' => ['short-token'],
    'token with spaces' => ['token with spaces in it'],
    'token longer than 512 characters' => [str_repeat('a', 513)],
]);

test('limits content to 240 characters', function () {
    expect((new PushChannel)->maxContentLength('Flash sale starts now!'))->toBe(240)
        ->and((new PushChannel)->contentLength('İndirim başladı 🎉'))->toBe(17);
});
