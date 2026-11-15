<?php

namespace App\Services\Delivery;

use App\Actions\ReportCircuitChangeAction;
use App\Data\DeliveryResultData;
use App\Data\GatewayResponseData;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Delivery\Contracts\NotificationGateway;
use App\Services\Observability\NotificationTracer;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The single entry point for delivering a notification.
 *
 * Callers such as the queue job only ask for a delivery and learn whether to try
 * again later; claiming the notification, calling the provider, recording the
 * attempt, classifying the result, computing the backoff and feeding the
 * channel's circuit breaker all happen here.
 */
final readonly class DeliveryService
{
    public function __construct(
        private NotificationGateway $gateway,
        private RetryBackoff $backoff,
        private CircuitBreaker $circuitBreaker,
        private ReportCircuitChangeAction $reportCircuitChange,
        private LoggerInterface $logger,
        private NotificationTracer $tracer,
        #[Config('notifications.delivery.max_attempts')] private int $maxAttempts,
    ) {}

    /**
     * Make one delivery attempt for the notification.
     *
     * Log entries written during the attempt carry the notification's id and the
     * correlation id of the request that created it, also when the job was pushed
     * by the stale-notification sweeper rather than by that request. The attempt
     * is traced in the notification's own trace (see NotificationTracer).
     */
    public function deliver(string $notificationId): DeliveryResultData
    {
        $notification = Notification::claimForDelivery($notificationId);

        if ($notification === null) {
            return $this->notClaimed($notificationId);
        }

        return Context::scope(
            fn (): DeliveryResultData => $this->tracer->traceAttempt($notification, fn (): DeliveryResultData => $this->attempt($notification)),
            ['notification_id' => $notification->id, 'correlation_id' => $notification->correlation_id],
        );
    }

    /**
     * Decide what a job does with a notification it could not claim.
     *
     * A notification that is only not due yet, because the job came before its next
     * attempt or its scheduled time, is waited for. Anything else (cancelled, being
     * delivered by another job, finished) needs nothing more from this job.
     */
    private function notClaimed(string $notificationId): DeliveryResultData
    {
        $secondsUntilDue = Notification::secondsUntilDue($notificationId);

        if ($secondsUntilDue !== null) {
            $this->logger->info('notification.delivery.deferred', [
                'notification_id' => $notificationId,
                'due_in_seconds' => $secondsUntilDue,
            ]);

            return DeliveryResultData::deferred($secondsUntilDue);
        }

        $this->logger->info('notification.delivery.skipped', ['notification_id' => $notificationId]);

        return DeliveryResultData::skipped();
    }

    /**
     * Call the provider for a claimed notification and move it to its next status.
     */
    private function attempt(Notification $notification): DeliveryResultData
    {
        $startedAt = hrtime(true);
        $response = $this->send($notification);
        $durationMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);

        try {
            $result = $this->record($notification, $response, $durationMilliseconds);
        } catch (Throwable $exception) {
            // The provider has answered, but nothing of it is stored; the log keeps the
            // answer, as the notification stays in processing until the stale sweep.
            $this->logger->error('notification.delivery.unrecorded', [
                ...$this->logContext($notification, $response, $durationMilliseconds),
                'outcome' => $response->outcome->value,
                'provider_message_id' => $response->providerMessageId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->recordForCircuit($notification->channel, $response->outcome);

        return $result;
    }

    /**
     * Store the attempt and move the notification to the status the provider's answer leads to.
     *
     * Both are written together: a database error in between can no longer record
     * an attempt while leaving the notification in processing, where the stale sweep
     * would send it once more.
     */
    private function record(Notification $notification, GatewayResponseData $response, int $durationMilliseconds): DeliveryResultData
    {
        return DB::transaction(function () use ($notification, $response, $durationMilliseconds): DeliveryResultData {
            $notification->deliveryAttempts()->create([
                'attempt' => $notification->attempts,
                'outcome' => $response->outcome,
                'http_status' => $response->httpStatus,
                'provider_message_id' => $response->providerMessageId,
                'error' => $response->error,
                'duration_ms' => $durationMilliseconds,
            ]);

            return match ($response->outcome) {
                GatewayOutcome::Accepted => $this->markSent($notification, $response, $durationMilliseconds),
                GatewayOutcome::PermanentFailure => $this->markFailed($notification, $response, $durationMilliseconds),
                GatewayOutcome::RetryableFailure => $notification->attempts >= $this->maxAttempts
                    ? $this->markFailed($notification, $response, $durationMilliseconds)
                    : $this->scheduleRetry($notification, $response, $durationMilliseconds),
            };
        });
    }

    /**
     * Feed the outcome to the channel's circuit breaker and report when the circuit opened or closed.
     *
     * The notification has already moved on at this point, so a circuit breaker or
     * reporting error is only reported instead of failing the delivery: the job would
     * otherwise be retried, find the notification finished and never report again.
     */
    private function recordForCircuit(NotificationChannel $channel, GatewayOutcome $outcome): void
    {
        try {
            $state = $this->circuitBreaker->record($channel, $outcome);

            if ($state !== null) {
                $this->reportCircuitChange->handle($channel, $state);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Call the provider, turning an unexpected error into a retryable failure.
     *
     * The notification is already claimed at this point; letting the error escape
     * would leave it stuck in processing instead of following the retry policy.
     */
    private function send(Notification $notification): GatewayResponseData
    {
        try {
            return $this->gateway->send($notification);
        } catch (Throwable $exception) {
            report($exception);

            return GatewayResponseData::retryable(__('notifications.errors.unexpected'));
        }
    }

    private function markSent(Notification $notification, GatewayResponseData $response, int $durationMilliseconds): DeliveryResultData
    {
        $notification->markSent($response->providerMessageId);

        $this->logger->info('notification.delivery.sent', [
            ...$this->logContext($notification, $response, $durationMilliseconds),
            'provider_message_id' => $response->providerMessageId,
        ]);

        return DeliveryResultData::sent();
    }

    private function markFailed(Notification $notification, GatewayResponseData $response, int $durationMilliseconds): DeliveryResultData
    {
        $notification->markFailed((string) $response->error);

        $this->logger->error('notification.delivery.failed', [
            ...$this->logContext($notification, $response, $durationMilliseconds),
            'error' => $response->error,
        ]);

        return DeliveryResultData::failed();
    }

    /**
     * Put the notification back for a later attempt.
     *
     * The next attempt is due at the start of its second, because the queue makes
     * the released job available on whole seconds; a due time with a fraction of a
     * second would make the job come early and spend a claim, a rate-limit slot and
     * a release before it may run.
     */
    private function scheduleRetry(Notification $notification, GatewayResponseData $response, int $durationMilliseconds): DeliveryResultData
    {
        $delaySeconds = $this->backoff->delayAfter($notification->attempts, $response->retryAfterSeconds);

        $notification->markRetryPending((string) $response->error, now()->addSeconds($delaySeconds)->startOfSecond());

        $this->logger->warning('notification.delivery.retry_scheduled', [
            ...$this->logContext($notification, $response, $durationMilliseconds),
            'error' => $response->error,
            'retry_in_seconds' => $delaySeconds,
        ]);

        return DeliveryResultData::retryScheduled($delaySeconds);
    }

    /**
     * Get the fields every delivery log entry shares.
     *
     * @return array{channel: string, priority: string, attempt: int, http_status: int|null, duration_ms: int}
     */
    private function logContext(Notification $notification, GatewayResponseData $response, int $durationMilliseconds): array
    {
        return [
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'attempt' => $notification->attempts,
            'http_status' => $response->httpStatus,
            'duration_ms' => $durationMilliseconds,
        ];
    }
}
