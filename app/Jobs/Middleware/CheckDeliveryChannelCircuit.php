<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Closure;
use Illuminate\Container\Container;
use Random\Randomizer;

/**
 * Lets a delivery job through only while its channel's circuit allows calling the provider.
 *
 * Otherwise the job goes back to the queue until the circuit may let it
 * through, without claiming its notification, so no delivery attempt is used up.
 *
 * Every job held back at the same time would come back in the same second, so
 * each waits a random extra time of up to the circuit's own wait: the jobs
 * return spread over the following window instead of all at once.
 *
 * A job let through as the probe of a half-open circuit gives the probe back
 * when it ends, so a probe that never called the provider (the job was
 * rate-limited, came early, found its notification cancelled or failed) does
 * not hold every other job back until the probe times out. A probe that did
 * call the provider has already settled the circuit, and giving it back
 * changes nothing.
 */
class CheckDeliveryChannelCircuit
{
    private CircuitBreaker $circuitBreaker;

    public function __construct(?CircuitBreaker $circuitBreaker = null, private Randomizer $randomizer = new Randomizer)
    {
        $this->circuitBreaker = $circuitBreaker ?? Container::getInstance()->make(CircuitBreaker::class);
    }

    /**
     * Process the queued job.
     *
     * @param  Closure(SendNotificationJob): void  $next
     */
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $permit = $this->circuitBreaker->permit($job->channel);

        if (! $permit->granted) {
            $waitSeconds = max(1, (int) ceil($permit->waitMilliseconds / 1000));

            $job->release($waitSeconds + $this->randomizer->getInt(0, $waitSeconds));

            return;
        }

        if (! $permit->isProbe()) {
            $next($job);

            return;
        }

        try {
            $next($job);
        } finally {
            $this->circuitBreaker->releaseProbe($job->channel, $permit);
        }
    }
}
