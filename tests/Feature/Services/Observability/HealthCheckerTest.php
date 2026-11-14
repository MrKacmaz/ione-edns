<?php

use App\Data\HealthCheckResultData;
use App\Enums\HealthCheckStatus;
use App\Services\Observability\Contracts\HealthCheck;
use App\Services\Observability\HealthChecker;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\StubHealthCheck;

test('reports a check that throws as failing without revealing the exception', function () {
    $broken = new class implements HealthCheck
    {
        public function name(): string
        {
            return 'broken';
        }

        public function check(): HealthCheckResultData
        {
            throw new RuntimeException('Connection refused by db.internal:5432');
        }
    };

    $results = (new HealthChecker([$broken]))->run();

    expect($results['broken']->status)->toBe(HealthCheckStatus::Failing)
        ->and($results['broken']->message)->toBe('The broken check failed.')
        ->and(HealthChecker::overallStatus($results))->toBe(HealthCheckStatus::Failing);
});

test('passes when every check passes', function () {
    $healthy = new class implements HealthCheck
    {
        public function name(): string
        {
            return 'healthy';
        }

        public function check(): HealthCheckResultData
        {
            return HealthCheckResultData::ok();
        }
    };

    $results = (new HealthChecker([$healthy]))->run();

    expect(HealthChecker::overallStatus($results))->toBe(HealthCheckStatus::Ok)
        ->and($results['healthy']->durationMilliseconds)->toBeFloat();
});

test('rates the service by its worst check', function (array $results, HealthCheckStatus $overallStatus) {
    expect(HealthChecker::overallStatus($results))->toBe($overallStatus);
})->with([
    'one check degraded' => [
        ['database' => HealthCheckResultData::ok(), 'delivery_circuits' => HealthCheckResultData::degraded('Paused.')],
        HealthCheckStatus::Degraded,
    ],
    'one check failing' => [
        ['database' => HealthCheckResultData::ok(), 'horizon' => HealthCheckResultData::failing('Down.')],
        HealthCheckStatus::Failing,
    ],
    'a failing check outweighs an earlier degraded one' => [
        ['delivery_circuits' => HealthCheckResultData::degraded('Paused.'), 'horizon' => HealthCheckResultData::failing('Down.')],
        HealthCheckStatus::Failing,
    ],
]);

test('keeps each check\'s own result under its name, in the order the checks are registered', function () {
    $checker = new HealthChecker([
        StubHealthCheck::returning('database', HealthCheckResultData::ok()),
        StubHealthCheck::returning('delivery_circuits', HealthCheckResultData::degraded('Deliveries are paused on sms.')),
    ]);

    $results = $checker->run();

    expect(array_keys($results))->toBe(['database', 'delivery_circuits'])
        ->and($results['delivery_circuits']->status)->toBe(HealthCheckStatus::Degraded)
        ->and($results['delivery_circuits']->message)->toBe('Deliveries are paused on sms.');
});

test('reports how long each check took in milliseconds', function () {
    $slow = new StubHealthCheck('slow', function (): HealthCheckResultData {
        $startedAt = hrtime(true);

        do {
            $elapsedNanoseconds = hrtime(true) - $startedAt;
        } while ($elapsedNanoseconds < 5_000_000);

        return HealthCheckResultData::ok();
    });

    $results = (new HealthChecker([$slow]))->run();

    expect($results['slow']->durationMilliseconds)
        ->toBeGreaterThanOrEqual(5.0)
        ->toBeLessThan(1000.0);
});

test('reports how long a check that throws took', function () {
    $broken = new StubHealthCheck('broken', fn (): HealthCheckResultData => throw new RuntimeException('Connection refused.'));

    $results = (new HealthChecker([$broken]))->run();

    expect($results['broken']->durationMilliseconds)->toBeFloat();
});

test('reports the exception of a check that throws, so operators find the cause in the logs', function () {
    Exceptions::fake();
    $broken = new StubHealthCheck('database', fn (): HealthCheckResultData => throw new RuntimeException('Connection refused by db.internal:5432'));

    (new HealthChecker([$broken]))->run();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Connection refused by db.internal:5432');
});
