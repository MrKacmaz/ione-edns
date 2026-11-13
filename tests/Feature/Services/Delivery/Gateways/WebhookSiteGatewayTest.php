<?php

use App\Enums\GatewayOutcome;
use App\Exceptions\GatewayNotConfiguredException;
use App\Models\Notification;
use App\Services\Delivery\Gateways\WebhookSiteGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
});

test('posts the notification with its id as idempotency key and reads the provider message id', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123', 'status' => 'accepted'], 202)]);
    $notification = Notification::factory()->queued()->create(['recipient' => '+905551234567', 'content' => 'Hello']);

    $response = app(WebhookSiteGateway::class)->send($notification);

    expect($response->outcome)->toBe(GatewayOutcome::Accepted)
        ->and($response->httpStatus)->toBe(202)
        ->and($response->providerMessageId)->toBe('msg-123');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.test/notify'
        && $request->method() === 'POST'
        && $request->header('Idempotency-Key') === [$notification->id]
        && $request->data() === ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Hello']);
});

test('passes the notification\'s correlation id on to the provider', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $notification = Notification::factory()->queued()->create(['correlation_id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);

    app(WebhookSiteGateway::class)->send($notification);

    Http::assertSent(fn (Request $request): bool => $request->header('X-Correlation-ID') === ['0f8fad5b-d9cb-469f-a165-70867728950e']);
});

test('treats server errors and rate limiting as retryable', function (int $status) {
    Http::fake(['gateway.test/*' => Http::response([], $status)]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->outcome)->toBe(GatewayOutcome::RetryableFailure)
        ->and($response->httpStatus)->toBe($status)
        ->and($response->error)->toBe("The provider responded with HTTP {$status}.");
})->with([
    'internal server error' => [500],
    'service unavailable' => [503],
    'too many requests' => [429],
]);

test('passes on the provider\'s Retry-After', function () {
    Http::fake(['gateway.test/*' => Http::response([], 429, ['Retry-After' => '45'])]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->retryAfterSeconds)->toBe(45);
});

test('treats other client errors as permanent', function (int $status) {
    Http::fake(['gateway.test/*' => Http::response([], $status)]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->outcome)->toBe(GatewayOutcome::PermanentFailure)
        ->and($response->httpStatus)->toBe($status);
})->with([
    'bad request' => [400],
    'unprocessable' => [422],
]);

test('treats an unreachable provider as retryable', function () {
    Http::fake(['gateway.test/*' => Http::failedConnection()]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->outcome)->toBe(GatewayOutcome::RetryableFailure)
        ->and($response->httpStatus)->toBeNull()
        ->and($response->error)->toStartWith('The provider could not be reached:');
});

test('refuses to send without a configured URL', function () {
    config(['notifications.gateway.url' => null]);

    app(WebhookSiteGateway::class)->send(Notification::factory()->create());
})->throws(GatewayNotConfiguredException::class, 'The notification gateway URL is not configured.');
