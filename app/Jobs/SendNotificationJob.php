<?php

namespace App\Jobs;

use App\Enums\DeliveryOutcome;
use App\Enums\NotificationChannel;
use App\Jobs\Middleware\AssignDeliveryTraceContext;
use App\Jobs\Middleware\AssignNotificationContext;
use App\Jobs\Middleware\CheckDeliveryChannelCircuit;
use App\Jobs\Middleware\RateLimitDeliveryChannel;
use App\Models\Notification as NotificationModel;
use App\Services\Delivery\DeliveryService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Throwable;

/**
 * Delivers one notification, retrying on transient failures with backoff.
 *
 * Retries are released back to the queue rather than counted by Laravel's
 * attempt limit: the notification's own attempt counter decides when to give up,
 * and waits for a rate-limit slot or an open circuit must not use up any attempts.
 *
 * With tracing enabled, each job run has its own trace linked to the trace of the
 * request that created the notification, so the request's trace does not collect a
 * span for every run, including the ones that only wait. The delivery attempt
 * itself is traced in the notification's trace (see NotificationTracer).
 */
class SendNotificationJob implements ShouldQueue, TracingLinked
{
    use Queueable;

    /**
     * Seconds the job may run; kept below the Horizon supervisor timeout and the queue's retry_after.
     */
    public int $timeout = 30;

    /**
     * @param  NotificationChannel  $channel  The notification's channel, so the rate limiter needs no database read.
     * @param  string|null  $correlationId  The notification's correlation id, for the job's log entries.
     */
    public function __construct(
        public readonly string $notificationId,
        public readonly NotificationChannel $channel,
        public readonly ?string $correlationId = null,
    ) {}

    /**
     * Get the seconds to wait before running again after an unexpected exception.
     *
     * Provider errors never get here, the delivery service handles them. What is left
     * is mostly a database or Redis outage: the job tries again every two minutes at
     * most until it runs out of time, so it is delivered soon after the outage ends.
     * Retries the delivery service schedules set their own delay and do not use these.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Get the time until which the job may be retried.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addDay();
    }

    /**
     * Get the middleware the job should pass through.
     *
     * The log context comes first, so every log entry of the job carries the
     * notification's ids and leads to the job's trace. The circuit is checked
     * before the rate limit, so a job of a channel with an open circuit does not
     * take up a send slot.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new AssignNotificationContext,
            new AssignDeliveryTraceContext,
            new CheckDeliveryChannelCircuit,
            new RateLimitDeliveryChannel,
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(DeliveryService $deliveryService): void
    {
        $result = $deliveryService->deliver($this->notificationId);

        if (in_array($result->outcome, [DeliveryOutcome::RetryScheduled, DeliveryOutcome::Deferred], true)) {
            $this->release($result->retryDelaySeconds ?? 0);
        }
    }

    /**
     * Fail the notification when the job itself gives up, for example after a timeout,
     * or when it runs out of time while its channel's circuit stays open.
     *
     * The notification gets a generic reason, as it is shown to API clients; the
     * exception itself stays in the failed job record and the log.
     */
    public function failed(?Throwable $exception): void
    {
        NotificationModel::markFailedIfUndelivered(
            $this->notificationId,
            __('notifications.errors.job_failed'),
        );
    }
}
