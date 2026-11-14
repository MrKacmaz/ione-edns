<?php

use App\Models\NotificationTemplate;
use App\Rules\NotificationTemplateRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Build the rule with the given templates as the user's templates the request refers to.
 */
function notificationTemplateRuleWith(NotificationTemplate ...$templates): NotificationTemplateRule
{
    return new NotificationTemplateRule(app(ChannelRegistry::class), (new Collection($templates))->keyBy('name'));
}

test('accepts a template of the notification\'s channel whose variables all have a text or number value', function (array $variables) {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']);

    $validator = Validator::make(
        ['channel' => 'sms', 'template' => 'order-shipped', 'variables' => $variables],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->passes())->toBeTrue();
})->with([
    'text' => [['name' => 'Ada', 'order_id' => 'A-1001']],
    'integer' => [['name' => 'Ada', 'order_id' => 1001]],
    'float' => [['name' => 'Ada', 'order_id' => 10.5]],
    'unused extra variable' => [['name' => 'Ada', 'order_id' => 1001, 'coupon' => 'SAVE10']],
]);

test('rejects a template name the user has no template for, whatever the channel', function (string $channel) {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']);

    $validator = Validator::make(
        ['channel' => $channel, 'template' => 'no-such-template'],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->errors()->first('template'))->toBe('The template "no-such-template" does not exist.');
})->with([
    'known channel' => ['sms'],
    'unknown channel' => ['fax'],
]);

test('rejects a template of another channel without checking its variables', function () {
    $template = NotificationTemplate::factory()->withoutParents()->email()->make(['name' => 'invoice-ready']);

    $validator = Validator::make(
        ['channel' => 'sms', 'template' => 'invoice-ready'],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->errors()->toArray())->toBe([
        'template' => ['The template "invoice-ready" is for the email channel, not the sms channel.'],
    ]);
});

test('leaves an existing template alone when the channel is unknown', function () {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']);

    $validator = Validator::make(
        ['channel' => 'fax', 'template' => 'order-shipped'],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->passes())->toBeTrue();
});

test('reports each variable without a text or number value on the variables attribute', function (array $variables, string $missingVariables) {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']);

    $validator = Validator::make(
        ['channel' => 'sms', 'template' => 'order-shipped', ...$variables],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->errors()->toArray())->toBe([
        'variables' => ["The variables must give a text or number value to: {$missingVariables}."],
    ]);
})->with([
    'no variables' => [[], 'name, order_id'],
    'missing variable' => [['variables' => ['name' => 'Ada']], 'order_id'],
    'null value' => [['variables' => ['name' => 'Ada', 'order_id' => null]], 'order_id'],
    'boolean value' => [['variables' => ['name' => 'Ada', 'order_id' => true]], 'order_id'],
    'array value' => [['variables' => ['name' => ['first' => 'Ada'], 'order_id' => 1001]], 'name'],
    'variables that are not an array' => [['variables' => 'Ada'], 'name, order_id'],
]);

test('accepts rendered content at the channel limit and rejects one character more', function (string $channel, int $maxContentLength) {
    $template = NotificationTemplate::factory()->withoutParents()->make([
        'name' => 'free-text',
        'channel' => $channel,
        'content' => '{{ text }}',
    ]);
    $rules = ['template' => [notificationTemplateRuleWith($template)]];

    $atLimit = Validator::make(['channel' => $channel, 'template' => 'free-text', 'variables' => ['text' => str_repeat('a', $maxContentLength)]], $rules);
    $overLimit = Validator::make(['channel' => $channel, 'template' => 'free-text', 'variables' => ['text' => str_repeat('a', $maxContentLength + 1)]], $rules);

    expect($atLimit->passes())->toBeTrue();
    expect($overLimit->errors()->first('template'))
        ->toBe("The content rendered from the template may not be greater than {$maxContentLength} characters for the {$channel} channel.");
})->with([
    'sms' => ['sms', 160],
    'email' => ['email', 10_000],
    'push' => ['push', 240],
]);

test('counts multibyte characters of the rendered content as single characters', function () {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'free-text', 'content' => '{{ text }}']);

    $validator = Validator::make(
        ['channel' => 'sms', 'template' => 'free-text', 'variables' => ['text' => str_repeat('ş', 160)]],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->passes())->toBeTrue();
});

test('validates a batch item against the channel and variables of the same item', function () {
    $rule = notificationTemplateRuleWith(
        NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']),
        NotificationTemplate::factory()->withoutParents()->email()->make(['name' => 'invoice-ready']),
    );

    $validator = Validator::make(['notifications' => [
        ['channel' => 'email', 'template' => 'invoice-ready', 'variables' => ['name' => 'Ada', 'invoice_id' => 7]],
        ['channel' => 'sms', 'template' => 'order-shipped', 'variables' => ['name' => 'Ada']],
    ]], ['notifications.*.template' => [$rule]]);

    expect($validator->errors()->toArray())->toBe([
        'notifications.1.variables' => ['The notifications.1.variables must give a text or number value to: order_id.'],
    ]);
});

test('leaves a value that is not a string to the field\'s other rules', function () {
    $template = NotificationTemplate::factory()->withoutParents()->make(['name' => 'order-shipped']);

    $validator = Validator::make(
        ['channel' => 'sms', 'template' => 42],
        ['template' => [notificationTemplateRuleWith($template)]],
    );

    expect($validator->passes())->toBeTrue();
});
