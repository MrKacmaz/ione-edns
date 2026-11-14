<?php

use App\Services\Observability\HealthChecker;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

test('runs every dependency check, in the order the health endpoint reports them', function () {
    $results = app(HealthChecker::class)->run();

    expect(array_keys($results))->toBe(['database', 'redis', 'horizon', 'gateway', 'delivery_circuits']);
});

test('propagates traces with the propagators the OpenTelemetry SDK was configured with', function () {
    expect(app(TextMapPropagatorInterface::class))->toBe(Globals::propagator());
});
