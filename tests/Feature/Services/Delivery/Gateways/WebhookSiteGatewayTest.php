<?php

use App\Enums\GatewayOutcome;
use App\Exceptions\GatewayNotConfiguredException;
use App\Models\Notification;
use App\Services\Delivery\Gateways\WebhookSiteGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    'request timeout' => [408],
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
        ->and($response->error)->toBe('The provider could not be reached.');
});

test('keeps the provider URL out of the stored error and logs the full reason instead', function () {
    Log::spy();
    $reason = 'cURL error 7: Failed to connect to gateway.test port 443 (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://gateway.test/notify';
    Http::fake(['gateway.test/*' => Http::failedConnection($reason)]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->error)->not->toContain('gateway.test');
    Log::shouldHaveReceived('warning')->once()->with('notification.gateway.unreachable', ['error' => $reason]);
});

test('refuses to send without a configured URL', function () {
    config(['notifications.gateway.url' => null]);

    app(WebhookSiteGateway::class)->send(Notification::factory()->create());
})->throws(GatewayNotConfiguredException::class, 'The notification gateway URL is not configured.');

test('reads the provider\'s Retry-After given in seconds or as an HTTP date', function (array $headers, ?int $retryAfterSeconds) {
    $this->travelTo('2026-11-14T12:00:00Z');
    Http::fake(['gateway.test/*' => Http::response([], 503, $headers)]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->retryAfterSeconds)->toBe($retryAfterSeconds);
})->with([
    'HTTP date 90 seconds ahead' => [['Retry-After' => 'Sat, 14 Nov 2026 12:01:30 GMT'], 90],
    'HTTP date in the past' => [['Retry-After' => 'Sat, 14 Nov 2026 11:59:00 GMT'], 0],
    'seconds surrounded by spaces' => [['Retry-After' => ' 30 '], 30],
    'unreadable value' => [['Retry-After' => 'soon'], null],
    'relative date a lenient parser would accept' => [['Retry-After' => '+1 year'], null],
    'date in another format' => [['Retry-After' => '2026-11-14T12:01:30Z'], null],
    'HTTP date with a day the month does not have' => [['Retry-After' => 'Tue, 31 Nov 2026 12:00:00 GMT'], null],
    'number too large for an int' => [['Retry-After' => '99999999999999999999'], PHP_INT_MAX],
    'no header' => [[], null],
]);

test('does not count a successful answer without a message id as sent', function (mixed $body, int $status) {
    Http::fake(['gateway.test/*' => Http::response($body, $status)]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->outcome)->toBe(GatewayOutcome::PermanentFailure)
        ->and($response->httpStatus)->toBe($status)
        ->and($response->providerMessageId)->toBeNull()
        ->and($response->error)->toBe("The provider responded with HTTP {$status} but without a messageId confirming the delivery.");
})->with([
    'empty JSON body' => [[], 202],
    'numeric message id' => [['messageId' => 12345], 202],
    'empty message id' => [['messageId' => ''], 202],
    'plain text body' => ['accepted', 202],
    'webhook.site default page' => ['<html>This URL has no default content configured.</html>', 200],
]);

test('treats a timed-out request as retryable and says that it timed out', function () {
    Http::fake(['gateway.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds for https://gateway.test/notify')]);

    $response = app(WebhookSiteGateway::class)->send(Notification::factory()->create());

    expect($response->outcome)->toBe(GatewayOutcome::RetryableFailure)
        ->and($response->error)->toBe('The provider did not answer in time.');
});

test('sends no correlation header when the notification has no correlation id', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $notification = Notification::factory()->queued()->create(['correlation_id' => null]);

    app(WebhookSiteGateway::class)->send($notification);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Correlation-ID'));
});

test('refuses to send when the configured URL is empty', function () {
    config(['notifications.gateway.url' => '']);

    app(WebhookSiteGateway::class)->send(Notification::factory()->create());
})->throws(GatewayNotConfiguredException::class, 'The notification gateway URL is not configured.');
