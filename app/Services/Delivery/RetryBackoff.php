<?php

namespace App\Services\Delivery;

use Illuminate\Container\Attributes\Config;
use Random\Randomizer;

/**
 * Exponential backoff with full jitter.
 *
 * After the n-th failed attempt the next attempt waits a random time between
 * zero and base * 2^(n-1) seconds, capped at the configured maximum. Spreading
 * retries randomly keeps notifications that failed together (for example during
 * a provider outage) from hitting the provider again all at once.
 */
final readonly class RetryBackoff
{
    public function __construct(
        #[Config('notifications.delivery.backoff.base_seconds')] private int $baseSeconds,
        #[Config('notifications.delivery.backoff.cap_seconds')] private int $capSeconds,
        #[Config('notifications.delivery.backoff.max_retry_after_seconds')] private int $maxRetryAfterSeconds,
        private Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * Get the seconds to wait before the next attempt.
     *
     * The wait is at least one second and never shorter than the provider's
     * Retry-After, which is honoured up to the configured maximum.
     */
    public function delayAfter(int $failedAttempts, ?int $retryAfterSeconds = null): int
    {
        $window = $this->windowAfter($failedAttempts);
        $retryAfterSeconds = min($retryAfterSeconds ?? 0, $this->maxRetryAfterSeconds);

        return max(1, $this->randomizer->getInt(0, $window), $retryAfterSeconds);
    }

    /**
     * Get the longest wait after the given number of failed attempts.
     */
    public function windowAfter(int $failedAttempts): int
    {
        $exponent = min(max(0, $failedAttempts - 1), 30);

        return min($this->capSeconds, $this->baseSeconds * (2 ** $exponent));
    }
}
