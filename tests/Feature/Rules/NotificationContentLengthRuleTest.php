<?php

use App\Rules\NotificationContentLengthRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Support\Facades\Validator;

test('accepts content at the channel limit and rejects one character more', function (string $channel, int $maxContentLength) {
    $rules = ['content' => [new NotificationContentLengthRule(app(ChannelRegistry::class))]];

    $atLimit = Validator::make(['channel' => $channel, 'content' => str_repeat('a', $maxContentLength)], $rules);
    $overLimit = Validator::make(['channel' => $channel, 'content' => str_repeat('a', $maxContentLength + 1)], $rules);

    expect($atLimit->passes())->toBeTrue()
        ->and($overLimit->errors()->first('content'))
        ->toBe("The content may not be greater than {$maxContentLength} characters for the {$channel} channel.");
})->with([
    'sms' => ['sms', 160],
    'email' => ['email', 10_000],
    'push' => ['push', 240],
]);

test('counts multibyte characters as single characters', function () {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => str_repeat('ş', 160)],
        ['content' => [new NotificationContentLengthRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
});

test('validates a batch item against the channel of the same item', function () {
    $validator = Validator::make([
        'notifications' => [
            ['channel' => 'email', 'content' => str_repeat('a', 200)],
            ['channel' => 'sms', 'content' => str_repeat('a', 200)],
        ],
    ], ['notifications.*.content' => [new NotificationContentLengthRule(app(ChannelRegistry::class))]]);

    expect($validator->errors()->keys())->toBe(['notifications.1.content']);
});
