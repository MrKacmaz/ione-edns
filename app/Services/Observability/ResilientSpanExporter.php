<?php

namespace App\Services\Observability;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\API\Common\Time\ClockInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Common\Future\CompletedFuture;
use OpenTelemetry\SDK\Common\Future\FutureInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use Throwable;

/**
 * A span exporter that stops calling a tracing backend that does not answer.
 *
 * PHP exports spans synchronously, so every export to an unreachable or hung
 * backend holds up a request or a delivery worker until it times out: with the
 * backend frozen, 300 SMS took minutes instead of seconds. After a failed export
 * this exporter drops spans without a network call for the cooldown, then lets one
 * export through as a probe; success resumes exporting, failure starts another
 * cooldown. Tracing is best-effort, delivery is not.
 *
 * The state is kept per process, so each PHP-FPM or Horizon process spends at most
 * one export timeout per cooldown on a dead backend.
 */
final class ResilientSpanExporter implements SpanExporterInterface
{
    use LogsMessagesTrait;

    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    /**
     * When exporting resumes, in nanoseconds of the clock; null while exporting.
     */
    private ?int $suspendedUntil = null;

    public function __construct(
        private readonly SpanExporterInterface $exporter,
        private readonly ClockInterface $clock,
        private readonly int $cooldownSeconds = 30,
    ) {}

    public function export(iterable $batch, ?CancellationInterface $cancellation = null): FutureInterface
    {
        if ($this->isSuspended()) {
            return new CompletedFuture(false);
        }

        try {
            $exported = $this->exporter->export($batch, $cancellation)->await() === true;
        } catch (Throwable) {
            $exported = false;
        }

        $exported ? $this->resume() : $this->suspend();

        return new CompletedFuture($exported);
    }

    public function shutdown(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->shutdown($cancellation);
    }

    public function forceFlush(?CancellationInterface $cancellation = null): bool
    {
        return $this->exporter->forceFlush($cancellation);
    }

    /**
     * Determine whether exports are being dropped because the backend failed recently.
     */
    public function isSuspended(): bool
    {
        return $this->suspendedUntil !== null && $this->clock->now() < $this->suspendedUntil;
    }

    private function suspend(): void
    {
        $this->suspendedUntil = $this->clock->now() + $this->cooldownSeconds * self::NANOSECONDS_PER_SECOND;

        self::logWarning('tracing.export_suspended', ['retry_in_seconds' => $this->cooldownSeconds]);
    }

    private function resume(): void
    {
        if ($this->suspendedUntil === null) {
            return;
        }

        $this->suspendedUntil = null;

        self::logInfo('tracing.export_resumed');
    }
}
