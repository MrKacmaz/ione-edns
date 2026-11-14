<?php

namespace App\Services\Observability;

use App\Data\MetricsSnapshotData;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Query\Builder;

/**
 * Gathers queue depth, in-flight notifications, success rates, latency and circuit states.
 *
 * Rates and latencies are computed over sliding windows from the delivery
 * history, so they reflect recent behaviour instead of all-time totals that a
 * table with millions of rows could not count quickly. The latency percentiles
 * use PostgreSQL's percentile_cont().
 *
 * @phpstan-import-type QueueMetrics from MetricsSnapshotData
 * @phpstan-import-type WindowMetrics from MetricsSnapshotData
 * @phpstan-import-type Latency from MetricsSnapshotData
 */
final readonly class MetricsCollector
{
    /**
     * The windows rates and latencies are computed over, in seconds.
     *
     * @var array<string, int>
     */
    private const array WINDOWS = ['5m' => 300, '1h' => 3600];

    private const string CACHE_KEY = 'notifications:metrics:snapshot';

    public function __construct(
        private QueueFactory $queues,
        private CircuitBreaker $circuitBreaker,
        private CacheRepository $cache,
        #[Config('notifications.metrics.cache_seconds')] private int $cacheSeconds,
    ) {}

    /**
     * Get the metrics from a snapshot that every caller shares for a few seconds.
     *
     * The endpoint, the dashboard and the five-second broadcast all read this, so
     * the aggregate queries run at most once per cache period however many
     * dashboards and scrapers ask. The cache keeps the snapshot as an array,
     * because it refuses to unserialize objects (`cache.serializable_classes`).
     */
    public function snapshot(): MetricsSnapshotData
    {
        if ($this->cacheSeconds === 0) {
            return $this->collect();
        }

        return MetricsSnapshotData::fromArray(
            $this->cache->remember(self::CACHE_KEY, $this->cacheSeconds, fn (): array => $this->collect()->toArray()),
        );
    }

    /**
     * Compute the metrics now.
     */
    public function collect(): MetricsSnapshotData
    {
        $now = CarbonImmutable::now();

        return new MetricsSnapshotData(
            generatedAt: $now,
            queues: $this->queueMetrics($now),
            inFlight: $this->inFlightNotifications(),
            windows: array_map(
                fn (string $window, int $seconds): array => $this->windowMetrics($window, $seconds, $now->subSeconds($seconds)),
                array_keys(self::WINDOWS),
                array_values(self::WINDOWS),
            ),
            circuits: $this->circuitStates(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function circuitStates(): array
    {
        $states = [];

        foreach (NotificationChannel::cases() as $channel) {
            $states[$channel->value] = $this->circuitBreaker->state($channel)->value;
        }

        return $states;
    }

    /**
     * Get the jobs on each notification queue.
     *
     * The age of the oldest pending job counts from when the job was first pushed,
     * as Laravel records it in the payload. A job that was delayed (a scheduled
     * notification) or released back (a retry, a rate-limit wait, an open circuit)
     * keeps that time, so the age includes those waits and reads as "how long the
     * oldest waiting delivery has been on its way" rather than "how long it has
     * been ready".
     *
     * @return list<QueueMetrics>
     */
    private function queueMetrics(CarbonImmutable $now): array
    {
        $connection = $this->queues->connection('redis');

        return array_map(function (NotificationPriority $priority) use ($connection, $now): array {
            $queue = $priority->queue();
            $oldestPendingAt = $connection->creationTimeOfOldestPendingJob($queue);

            return [
                'queue' => $queue,
                'pending' => (int) $connection->pendingSize($queue),
                'delayed' => (int) $connection->delayedSize($queue),
                'reserved' => (int) $connection->reservedSize($queue),
                'oldest_pending_age_seconds' => $oldestPendingAt === null ? null : max(0, $now->getTimestamp() - (int) $oldestPendingAt),
            ];
        }, NotificationPriority::cases());
    }

    /**
     * @return array<string, int>
     */
    private function inFlightNotifications(): array
    {
        $unfinished = array_values(array_filter(
            NotificationStatus::cases(),
            fn (NotificationStatus $status): bool => ! in_array($status, NotificationStatus::final(), true),
        ));

        $counts = Notification::query()
            ->whereIn('status', $unfinished)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $inFlight = [];

        foreach ($unfinished as $status) {
            $inFlight[$status->value] = (int) $counts->get($status->value, 0);
        }

        return $inFlight;
    }

    /**
     * @return WindowMetrics
     */
    private function windowMetrics(string $window, int $seconds, CarbonImmutable $since): array
    {
        // Formatted with the models' date format, as the query builder alone would drop the
        // microseconds and widen the window by up to a second.
        $from = (new NotificationAttempt)->fromDateTime($since);

        $attempts = NotificationAttempt::query()
            ->where('created_at', '>=', $from)
            ->toBase()
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $accepted = (int) $attempts->get(GatewayOutcome::Accepted->value, 0);
        $retryable = (int) $attempts->get(GatewayOutcome::RetryableFailure->value, 0);
        $permanent = (int) $attempts->get(GatewayOutcome::PermanentFailure->value, 0);

        $failed = Notification::query()
            ->where('status', NotificationStatus::Failed)
            ->where('failed_at', '>=', $from)
            ->count();

        return [
            'window' => $window,
            'seconds' => $seconds,
            'attempts' => [
                'total' => $accepted + $retryable + $permanent,
                'accepted' => $accepted,
                'retryable_failure' => $retryable,
                'permanent_failure' => $permanent,
            ],
            'notifications' => ['sent' => $accepted, 'failed' => $failed],
            'success_rate' => $accepted + $failed === 0 ? null : round($accepted / ($accepted + $failed), 4),
            'latency_ms' => [
                'provider' => $this->percentiles(
                    NotificationAttempt::query()->where('created_at', '>=', $from)->toBase(),
                    'duration_ms',
                ),
                'end_to_end' => $this->percentiles(
                    NotificationAttempt::query()
                        ->join('notifications', 'notifications.id', '=', 'notification_attempts.notification_id')
                        ->where('notification_attempts.outcome', GatewayOutcome::Accepted)
                        ->where('notification_attempts.created_at', '>=', $from)
                        ->toBase(),
                    'extract(epoch from (notification_attempts.created_at - coalesce(notifications.scheduled_at, notifications.created_at))) * 1000',
                ),
            ],
        ];
    }

    /**
     * Compute the median, 95th and 99th percentile of an expression.
     *
     * @param  literal-string  $expression  A fixed SQL expression; never user input.
     * @return Latency
     */
    private function percentiles(Builder $query, string $expression): array
    {
        $row = $query->selectRaw(
            "percentile_cont(0.5) within group (order by {$expression}) as p50, "
            ."percentile_cont(0.95) within group (order by {$expression}) as p95, "
            ."percentile_cont(0.99) within group (order by {$expression}) as p99",
        )->first();

        return [
            'p50' => self::milliseconds($row?->p50),
            'p95' => self::milliseconds($row?->p95),
            'p99' => self::milliseconds($row?->p99),
        ];
    }

    private static function milliseconds(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 1) : null;
    }
}
