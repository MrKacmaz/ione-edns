<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Closure;
use Illuminate\Container\Container;

/**
 * Lets a delivery job through only while its channel's circuit allows calling the provider.
 *
 * Otherwise the job goes back to the queue until the circuit may let it
 * through, without claiming its notification, so no delivery attempt is used up.
 */
class CheckDeliveryChannelCircuit
{
    private CircuitBreaker $circuitBreaker;

    public function __construct(?CircuitBreaker $circuitBreaker = null)
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
            $job->release(max(1, (int) ceil($permit->waitMilliseconds / 1000)));

            return;
        }

        $next($job);
    }
}
