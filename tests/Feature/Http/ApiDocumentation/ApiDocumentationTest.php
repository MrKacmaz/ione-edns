<?php

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;

/**
 * Generate the OpenAPI document the way /docs/api and `scramble:export` do.
 *
 * @return array<string, mixed>
 */
function apiDocument(): array
{
    return app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
}

test('documents 404 instead of 403 for another user\'s records', function (string $path, string $method) {
    $responses = apiDocument()['paths'][$path][$method]['responses'];

    expect($responses)->toHaveKey('404')->not->toHaveKey('403');
})->with([
    'notification' => ['/v1/notifications/{notification}', 'get'],
    'cancel' => ['/v1/notifications/{notification}/cancel', 'post'],
    'retry' => ['/v1/notifications/{notification}/retry', 'post'],
    'batch' => ['/v1/batches/{batch}', 'get'],
    'template update' => ['/v1/templates/{template}', 'patch'],
]);

test('keeps the 403 of the metrics, which only operators may read', function () {
    expect(apiDocument()['paths']['/v1/metrics']['get']['responses'])->toHaveKey('403');
});

test('documents the Idempotency-Key header and its responses on both create endpoints', function (string $path) {
    $operation = apiDocument()['paths'][$path]['post'];

    expect(array_column($operation['parameters'], 'name'))->toContain('Idempotency-Key')
        ->and($operation['responses'])->toHaveKeys(['400', '409', '422']);
})->with(['/v1/notifications', '/v1/notifications/batch']);

test('documents the X-Correlation-ID header on every operation', function () {
    $operations = collect(apiDocument()['paths'])->flatMap(fn (array $methods): array => array_values($methods));

    expect($operations)->each(fn ($operation) => $operation
        ->toHaveKey('parameters')
        ->and(array_column($operation->value['parameters'], 'name'))->toContain('X-Correlation-ID'));
});

test('documents the 429 of the API rate limit and its Retry-After header on every operation', function () {
    $operations = collect(apiDocument()['paths'])->flatMap(fn (array $methods): array => array_values($methods));

    expect($operations)->each->toHaveKey('responses.429.headers.Retry-After');
});

test('describes template variables as an object of text or number values', function () {
    $schemas = apiDocument()['components']['schemas'];

    $single = $schemas['StoreNotificationRequest']['properties']['variables'];
    $batch = $schemas['StoreNotificationBatchRequest']['properties']['notifications'];

    expect($single['type'])->toBe('object')
        ->and($single['additionalProperties']['type'])->toBe(['string', 'number'])
        ->and($batch['items']['properties']['variables'])->toBe($single)
        ->and($batch)->not->toHaveKey('uniqueItems');
});

test('puts the create request examples on the request body instead of the schema', function (string $path, string $schema, array $example) {
    $document = apiDocument();
    $content = $document['paths'][$path]['post']['requestBody']['content']['application/json'];

    expect($content['example'])->toMatchArray($example)
        ->and($document['components']['schemas'][$schema])->not->toHaveKey('examples');
})->with([
    'notification' => ['/v1/notifications', 'StoreNotificationRequest', ['channel' => 'sms', 'recipient' => '+905551234567']],
    'batch' => ['/v1/notifications/batch', 'StoreNotificationBatchRequest', ['notifications' => [
        ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Your order has shipped.', 'priority' => 'high'],
        ['channel' => 'email', 'recipient' => 'customer@example.com', 'content' => 'Your invoice is ready.', 'priority' => 'low'],
    ]]],
    'template' => ['/v1/templates', 'StoreNotificationTemplateRequest', ['name' => 'order-shipped', 'channel' => 'sms']],
]);

test('leaves the WebSocket authorization endpoint out of the API documentation', function () {
    expect(array_keys(apiDocument()['paths']))->not->toContain('/v1/broadcasting/auth');
});
