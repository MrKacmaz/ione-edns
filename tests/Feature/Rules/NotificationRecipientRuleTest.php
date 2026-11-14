<?php

use App\Rules\NotificationRecipientRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Support\Facades\Validator;

test('validates a batch item against the channel of the same item', function () {
    $validator = Validator::make([
        'notifications' => [
            ['channel' => 'email', 'recipient' => 'customer@example.com'],
            ['channel' => 'sms', 'recipient' => 'customer@example.com'],
        ],
    ], ['notifications.*.recipient' => [new NotificationRecipientRule(app(ChannelRegistry::class))]]);

    expect($validator->errors()->keys())->toBe(['notifications.1.recipient']);
});

test('describes the recipient format the channel expects', function (string $channel, string $recipient, string $message) {
    $validator = Validator::make(
        ['channel' => $channel, 'recipient' => $recipient],
        ['recipient' => [new NotificationRecipientRule(app(ChannelRegistry::class))]],
    );

    expect($validator->errors()->first('recipient'))->toBe($message);
})->with([
    'sms' => ['sms', 'not-a-phone', 'The recipient must be an E.164 phone number (e.g. +905551234567) for the sms channel.'],
    'email' => ['email', 'not-an-email', 'The recipient must be a valid email address for the email channel.'],
    'push' => ['push', 'short', 'The recipient must be a device token of 16 to 512 characters for the push channel.'],
]);

test('leaves the recipient alone when the channel is unknown', function () {
    $validator = Validator::make(
        ['channel' => 'fax', 'recipient' => 'anything'],
        ['recipient' => [new NotificationRecipientRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
});
