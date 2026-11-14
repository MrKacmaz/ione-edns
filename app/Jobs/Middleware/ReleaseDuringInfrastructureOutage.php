<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Observability\InfrastructureOutageDetector;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use PDOException;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * Waits out a database or Redis outage instead of failing the delivery.
 *
 * Laravel counts every exception towards the job's maxExceptions, so a few minutes
 * without the database would fail notifications that never reached the provider.
 * An error that only says the database or Redis cannot be reached puts the job
 * back on the queue with a growing delay instead, without counting; the job keeps
 * trying until its one-day lifetime ends. Every other error still counts.
 *
 * Placed first, it also covers the circuit breaker and the rate limiter, which
 * read Redis before the delivery starts.
 */
class ReleaseDuringInfrastructureOutage
{
    /**
     * The delay after the first outage, doubled after each further one.
     */
    private const int BASE_DELAY_SECONDS = 10;

    /**
     * The longest delay between two tries during an outage.
     */
    private const int MAX_DELAY_SECONDS = 300;

    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null, private InfrastructureOutageDetector $outages = new InfrastructureOutageDetector)
    {
        $this->logger = $logger ?? Container::getInstance()->make(LoggerInterface::class);
    }

    /**
     * Process the queued job.
     *
     * @param  Closure(SendNotificationJob): void  $next
     *
     * @throws QueryException|PDOException|RedisException when the error is not an outage
     */
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (PDOException|RedisException $exception) {
            if (! $this->outages->isOutage($exception)) {
                throw $exception;
            }

            $delaySeconds = $this->delayAfter($job->attempts());

            $this->logger->warning('notification.delivery.infrastructure_unavailable', [
                'notification_id' => $job->notificationId,
                'error' => $exception->getMessage(),
                'retry_in_seconds' => $delaySeconds,
            ]);

            $job->release($delaySeconds);
        }
    }

    /**
     * Get the delay before the next try of a job that has run the given number of times.
     */
    private function delayAfter(int $attempts): int
    {
        return min(self::MAX_DELAY_SECONDS, self::BASE_DELAY_SECONDS * 2 ** min(max(0, $attempts - 1), 30));
    }
}
