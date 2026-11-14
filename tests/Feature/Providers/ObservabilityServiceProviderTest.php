<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

test('propagates traces with the propagators the OpenTelemetry SDK was configured with', function () {
    expect(app(TextMapPropagatorInterface::class))->toBe(Globals::propagator());
});
