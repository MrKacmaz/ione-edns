<?php

namespace App\Services\Observability;

use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\TracerProviderInterface as SdkTracerProviderInterface;

/**
 * Exports a worker's finished spans between two jobs, at most once a second.
 *
 * The SDK exports in batches, from inside whichever span ends after its delay, so
 * a worker's spans could wait in memory until its next job, and the export ran in
 * the middle of that job. Flushing after each job shows the trace in the tracing UI
 * right away and keeps the export off the delivery itself.
 */
final class SpanFlusher
{
    private const int MINIMUM_INTERVAL_NANOSECONDS = 1_000_000_000;

    private ?int $lastFlushedAt = null;

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Export the finished spans, unless that was done less than a second ago or tracing is off.
     */
    public function flushIfDue(): void
    {
        if (! $this->tracerProvider instanceof SdkTracerProviderInterface) {
            return;
        }

        $now = $this->clock->now();

        if ($this->lastFlushedAt !== null && $now - $this->lastFlushedAt < self::MINIMUM_INTERVAL_NANOSECONDS) {
            return;
        }

        $this->lastFlushedAt = $now;
        $this->tracerProvider->forceFlush();
    }
}
