<?php

use App\Services\Observability\ResilientSpanExporter;
use App\Services\Observability\SpanFlusher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

test('propagates traces with the propagators the OpenTelemetry SDK was configured with', function () {
    expect(app(TextMapPropagatorInterface::class))->toBe(Globals::propagator());
});

test('offers the resilient OTLP exporter under the name OTEL_TRACES_EXPORTER selects it by', function () {
    $exporter = Registry::spanExporterFactory('otlp_resilient')->create();

    expect($exporter)->toBeInstanceOf(ResilientSpanExporter::class)
        ->and($exporter->isSuspended())->toBeFalse();
});

test('exports a worker\'s finished spans after every job attempt', function () {
    $clock = new TestClock;
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new BatchSpanProcessor($exporter, $clock));
    $this->app->instance(SpanFlusher::class, new SpanFlusher($provider, $clock));
    $provider->getTracer('tests')->spanBuilder('notification.deliver')->startSpan()->end();

    event(new JobAttempted('redis', Mockery::mock(Job::class)));

    expect($exporter->getSpans())->toHaveCount(1);
});

test('keeps one span flusher per process, as it remembers when it last flushed', function () {
    expect(app(SpanFlusher::class))->toBe(app(SpanFlusher::class));
});
