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

test('limits content to one SMS: 160 septets in GSM-7, or 70 UTF-16 units once a character needs UCS-2', function (string $content, int $length, int $maxLength) {
    $channel = new SmsChannel;

    expect($channel->contentLength($content))->toBe($length)
        ->and($channel->maxContentLength($content))->toBe($maxLength);
})->with([
    'plain text' => ['Your order has shipped.', 23, 160],
    'every character of the basic GSM-7 set' => ["@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà", 127, 160],
    'GSM-7 extension characters take two septets' => ['€5 {code} [x] ^~|\\', 27, 160],
    'a Turkish character switches to UCS-2' => ['Siparişiniz kargoda.', 20, 70],
    'an emoji takes two UTF-16 units' => ['Sale 🎉', 7, 70],
    'empty' => ['', 0, 160],
]);
