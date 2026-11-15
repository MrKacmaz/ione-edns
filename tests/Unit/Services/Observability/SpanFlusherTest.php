<?php

use App\Services\Observability\SpanFlusher;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

test('exports the finished spans after a job, at most once a second', function () {
    $clock = new TestClock;
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new BatchSpanProcessor($exporter, $clock));
    $flusher = new SpanFlusher($provider, $clock);

    $provider->getTracer('tests')->spanBuilder('first')->startSpan()->end();
    $flusher->flushIfDue();
    $provider->getTracer('tests')->spanBuilder('second')->startSpan()->end();
    $clock->advance(500_000_000);
    $flusher->flushIfDue();
    $exportedWithinTheSecond = count($exporter->getSpans());
    $clock->advance(500_000_000);
    $flusher->flushIfDue();

    expect($exportedWithinTheSecond)->toBe(1)
        ->and(array_map(fn ($span) => $span->getName(), $exporter->getSpans()))->toBe(['first', 'second']);
});

test('does nothing while tracing is off', function () {
    (new SpanFlusher(new NoopTracerProvider, new TestClock))->flushIfDue();
})->throwsNoExceptions();
