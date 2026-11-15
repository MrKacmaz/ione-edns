<?php

use App\Services\Observability\ResilientSpanExporter;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * A span exporter whose exports succeed, fail or throw as told, counting the calls that reached it.
 */
function scriptedSpanExporter(): SpanExporterInterface
{
    return new class implements SpanExporterInterface
    {
        public int $calls = 0;

        public bool|RuntimeException $nextResult = true;

        public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
        {
            $this->calls++;

            if ($this->nextResult instanceof RuntimeException) {
                throw $this->nextResult;
            }

            return new CompletedFuture($this->nextResult);
        }

        public function shutdown(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }

        public function forceFlush(?CancellationInterface $cancellation = null): bool
        {
            return true;
        }
    };
}

test('passes exports through while the backend answers', function () {
    $backend = scriptedSpanExporter();
    $exporter = new ResilientSpanExporter($backend, new TestClock, cooldownSeconds: 30);

    $results = [$exporter->export([])->await(), $exporter->export([])->await()];

    expect($results)->toBe([true, true])
        ->and($backend->calls)->toBe(2)
        ->and($exporter->isSuspended())->toBeFalse();
});

test('drops spans without calling the backend for the cooldown after a failed export', function () {
    $backend = scriptedSpanExporter();
    $clock = new TestClock;
    $exporter = new ResilientSpanExporter($backend, $clock, cooldownSeconds: 30);
    $backend->nextResult = false;

    $failed = $exporter->export([])->await();
    $clock->advanceSeconds(29);
    $dropped = $exporter->export([])->await();

    expect($failed)->toBeFalse()
        ->and($dropped)->toBeFalse()
        ->and($backend->calls)->toBe(1)
        ->and($exporter->isSuspended())->toBeTrue();
});

test('lets one probe through after the cooldown and resumes when it succeeds', function () {
    $backend = scriptedSpanExporter();
    $clock = new TestClock;
    $exporter = new ResilientSpanExporter($backend, $clock, cooldownSeconds: 30);
    $backend->nextResult = false;
    $exporter->export([]);

    $clock->advanceSeconds(30);
    $backend->nextResult = true;
    $probe = $exporter->export([])->await();
    $next = $exporter->export([])->await();

    expect([$probe, $next])->toBe([true, true])
        ->and($backend->calls)->toBe(3)
        ->and($exporter->isSuspended())->toBeFalse();
});

test('starts another cooldown when the probe fails', function () {
    $backend = scriptedSpanExporter();
    $clock = new TestClock;
    $exporter = new ResilientSpanExporter($backend, $clock, cooldownSeconds: 30);
    $backend->nextResult = false;
    $exporter->export([]);

    $clock->advanceSeconds(30);
    $exporter->export([]);
    $clock->advanceSeconds(10);
    $exporter->export([]);

    expect($backend->calls)->toBe(2)
        ->and($exporter->isSuspended())->toBeTrue();
});

test('treats an exception of the backend as a failed export instead of letting it through', function () {
    $backend = scriptedSpanExporter();
    $exporter = new ResilientSpanExporter($backend, new TestClock, cooldownSeconds: 30);
    $backend->nextResult = new RuntimeException('Connection refused.');

    expect($exporter->export([])->await())->toBeFalse()
        ->and($exporter->isSuspended())->toBeTrue();
});
