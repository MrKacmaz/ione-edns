<?php

use App\Data\HealthCheckResultData;

test('serializes each kind of result with its status code, its message and no duration', function (HealthCheckResultData $result, array $expected) {
    expect($result->toArray())->toBe($expected);
})->with([
    'passing check' => [
        HealthCheckResultData::ok(),
        ['status' => 'ok', 'message' => null, 'duration_ms' => null],
    ],
    'degraded check' => [
        HealthCheckResultData::degraded('2 of 3 delivery circuits are open.'),
        ['status' => 'warn', 'message' => '2 of 3 delivery circuits are open.', 'duration_ms' => null],
    ],
    'failing check' => [
        HealthCheckResultData::failing('Connection refused.'),
        ['status' => 'fail', 'message' => 'Connection refused.', 'duration_ms' => null],
    ],
]);

test('keeps the status and the message when the duration is added', function () {
    $result = HealthCheckResultData::degraded('Horizon is paused.');

    $timedResult = $result->withDuration(42.0);

    expect($timedResult->toArray())->toBe([
        'status' => 'warn',
        'message' => 'Horizon is paused.',
        'duration_ms' => 42.0,
    ]);
});

test('rounds the duration to one decimal place', function (float $milliseconds, float $expectedMilliseconds) {
    $result = HealthCheckResultData::ok()->withDuration($milliseconds);

    expect($result->durationMilliseconds)->toBe($expectedMilliseconds);
})->with([
    'rounds down' => [12.34, 12.3],
    'rounds up' => [12.36, 12.4],
    'sub-millisecond check' => [0.04, 0.0],
    'whole milliseconds stay unchanged' => [250.0, 250.0],
]);
