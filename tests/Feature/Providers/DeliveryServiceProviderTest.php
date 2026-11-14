<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use App\Services\Delivery\CircuitBreaking\RedisCircuitBreaker;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Delivery\Contracts\NotificationGateway;
use App\Services\Delivery\Gateways\WebhookSiteGateway;
use App\Services\Delivery\RateLimiting\RedisGcraRateLimiter;

test('registers a delivery channel for every notification channel', function (NotificationChannel $channel) {
    $deliveryChannel = app(ChannelRegistry::class)->for($channel);

    expect($deliveryChannel->channel())->toBe($channel);
})->with(NotificationChannel::cases());

test('shares one channel registry across the application', function () {
    expect(app(ChannelRegistry::class))->toBe(app(ChannelRegistry::class));
});

test('delivers through the webhook.site gateway', function () {
    expect(app(NotificationGateway::class))->toBeInstanceOf(WebhookSiteGateway::class);
});

test('shares one GCRA rate limiter across the application', function () {
    expect(app(ChannelRateLimiter::class))
        ->toBeInstanceOf(RedisGcraRateLimiter::class)
        ->toBe(app(ChannelRateLimiter::class));
});

test('shares one Redis circuit breaker across the application', function () {
    // The base test case swaps in a fake circuit breaker; dropping it exposes the provider's binding.
    $this->app->forgetInstance(CircuitBreaker::class);

    expect(app(CircuitBreaker::class))
        ->toBeInstanceOf(RedisCircuitBreaker::class)
        ->toBe(app(CircuitBreaker::class));
});
