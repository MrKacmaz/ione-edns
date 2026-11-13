<?php

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The state of the delivery system at one moment.
 *
 * @phpstan-type QueueMetrics array{queue: string, pending: int, delayed: int, reserved: int, oldest_pending_age_seconds: int|null}
 * @phpstan-type Latency array{p50: float|null, p95: float|null, p99: float|null}
 * @phpstan-type WindowMetrics array{
 *     window: string,
 *     seconds: int,
 *     attempts: array{total: int, accepted: int, retryable_failure: int, permanent_failure: int},
 *     notifications: array{sent: int, failed: int},
 *     success_rate: float|null,
 *     latency_ms: array{provider: Latency, end_to_end: Latency},
 * }
 */
final readonly class MetricsSnapshotData
{
    /**
     * @param  list<QueueMetrics>  $queues
     * @param  array<string, int>  $inFlight  Notifications per unfinished status.
     * @param  list<WindowMetrics>  $windows
     * @param  array<string, string>  $circuits  The circuit state per channel.
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public array $queues,
        public array $inFlight,
        public array $windows,
        public array $circuits,
    ) {}

    /**
     * @return array{generated_at: string, queues: list<QueueMetrics>, notifications_in_flight: array<string, int>, windows: list<WindowMetrics>, circuits: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt->toIso8601ZuluString('microsecond'),
            'queues' => $this->queues,
            'notifications_in_flight' => $this->inFlight,
            'windows' => $this->windows,
            'circuits' => $this->circuits,
        ];
    }
}
