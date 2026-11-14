<?php

use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecks\GatewayHealthCheck;

test('passes when the gateway URL is configured', function () {
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);

    $result = app(GatewayHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Ok)
        ->and($result->message)->toBeNull();
});

test('fails without calling the gateway when its URL is not configured', function (?string $url) {
    config(['notifications.gateway.url' => $url]);

    $result = app(GatewayHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Failing)
        ->and($result->message)->toBe('The notification gateway URL is not configured.');
})->with([
    'missing' => [null],
    'empty' => [''],
]);
