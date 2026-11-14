<?php

use App\Enums\NotificationChannel;
use App\Rules\NotificationTemplateContentRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Support\Facades\Validator;

test('accepts content whose double braces all form placeholders', function (string $content) {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => $content],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
})->with([
    'padded name' => ['Hi {{ name }}'],
    'unpadded name' => ['Hi {{name}}'],
    'name with digits and underscores' => ['Order {{ order_id2 }} has shipped.'],
    'name with a leading underscore' => ['Hi {{ _name }}'],
    'no placeholder' => ['Your order has shipped.'],
    'single braces' => ['Use the code {SAVE10} at checkout.'],
]);

test('rejects double braces that do not form a placeholder', function (string $content) {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => $content],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->errors()->first('content'))
        ->toBe('The content may only use placeholders like {{ name }}, whose names consist of letters, digits and underscores.');
})->with([
    'space in the name' => ['Hi {{ first name }}'],
    'dash in the name' => ['Hi {{ first-name }}'],
    'leading digit' => ['Hi {{ 1st }}'],
    'empty' => ['Hi {{ }}'],
    'unclosed' => ['Hi {{ name'],
    'unopened' => ['Hi name }}'],
    'unclosed after a valid placeholder' => ['Hi {{ name }}, your order {{ order_id'],
]);

test('accepts fixed text at the channel limit and rejects one character more, not counting placeholders', function (string $channel, int $maxContentLength) {
    $rules = ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]];

    $atLimit = Validator::make(['channel' => $channel, 'content' => str_repeat('a', $maxContentLength).'{{ name }}'], $rules);
    $overLimit = Validator::make(['channel' => $channel, 'content' => str_repeat('a', $maxContentLength + 1).'{{ name }}'], $rules);

    expect($atLimit->passes())->toBeTrue();
    expect($overLimit->errors()->first('content'))
        ->toBe("The content may not be greater than {$maxContentLength} characters for the {$channel} channel, not counting its placeholders.");
})->with([
    'sms' => ['sms', 160],
    'email' => ['email', 10_000],
    'push' => ['push', 240],
]);

test('counts multibyte characters of the fixed text as single characters', function () {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => str_repeat('ş', 160)],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
});

test('checks the length against the given channel instead of the channel in the data', function () {
    $validator = Validator::make(
        ['channel' => 'email', 'content' => str_repeat('a', 161)],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class), NotificationChannel::Sms)]],
    );

    expect($validator->errors()->first('content'))
        ->toBe('The content may not be greater than 160 characters for the sms channel, not counting its placeholders.');
});

test('reports only the malformed placeholder when the content is also too long', function () {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => str_repeat('a', 161).'{{ 1st }}'],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->errors()->get('content'))->toBe([
        'The content may only use placeholders like {{ name }}, whose names consist of letters, digits and underscores.',
    ]);
});

test('leaves the length alone when the channel is unknown', function () {
    $validator = Validator::make(
        ['channel' => 'fax', 'content' => str_repeat('a', 10_001)],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
});

test('rejects a malformed placeholder even when the channel is unknown', function () {
    $validator = Validator::make(
        ['channel' => 'fax', 'content' => 'Hi {{ 1st }}'],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->errors()->keys())->toBe(['content']);
});

test('leaves a value that is not a string to the field\'s other rules', function () {
    $validator = Validator::make(
        ['channel' => 'sms', 'content' => ['Hi {{ 1st }}']],
        ['content' => [new NotificationTemplateContentRule(app(ChannelRegistry::class))]],
    );

    expect($validator->passes())->toBeTrue();
});
