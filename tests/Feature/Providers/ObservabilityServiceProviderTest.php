<?php

use App\Services\Observability\HealthChecker;

test('runs every dependency check, in the order the health endpoint reports them', function () {
    $results = app(HealthChecker::class)->run();

    expect(array_keys($results))->toBe(['database', 'redis', 'horizon', 'gateway', 'delivery_circuits']);
});
