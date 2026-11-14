<?php

use App\Enums\CircuitState;
use App\Enums\HealthCheckStatus;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Observability\HealthChecks\DeliveryCircuitsHealthCheck;
use Tests\Support\FakeCircuitBreaker;

test('passes while every channel\'s circuit is closed', function () {
    $result = app(DeliveryCircuitsHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Ok)
        ->and($result->message)->toBeNull();
});

test('reports the service degraded while a channel\'s circuit is not closed', function (CircuitState $state) {
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: ['email' => $state]));

    $result = app(DeliveryCircuitsHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Degraded)
        ->and($result->message)->toBe('Deliveries are paused on these channels until their provider recovers: email.');
})->with([
    'open' => [CircuitState::Open],
    'half-open' => [CircuitState::HalfOpen],
]);

test('names every paused channel', function () {
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: [
        'push' => CircuitState::Open,
        'sms' => CircuitState::Open,
        'email' => CircuitState::HalfOpen,
    ]));

    $result = app(DeliveryCircuitsHealthCheck::class)->check();

    expect($result->message)->toBe('Deliveries are paused on these channels until their provider recovers: sms, email, push.');
});
