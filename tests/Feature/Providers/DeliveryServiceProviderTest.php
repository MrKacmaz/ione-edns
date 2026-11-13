<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use App\Services\Delivery\Contracts\NotificationGateway;
use App\Services\Delivery\Gateways\WebhookSiteGateway;

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
