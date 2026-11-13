<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Sleep;

/**
 * Lets a delivery job through only when its channel has a free send slot.
 *
 * A slot that opens within the limiter's wait budget is awaited in place, which
 * keeps the job's place in the queue; otherwise the job goes back to the queue
 * until the slot opens.
 */
class RateLimitDeliveryChannel
{
    private ChannelRateLimiter $rateLimiter;

    public function __construct(?ChannelRateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter ?? Container::getInstance()->make(ChannelRateLimiter::class);
    }

    /**
     * Process the queued job.
     *
     * @param  Closure(SendNotificationJob): void  $next
     */
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $reservation = $this->rateLimiter->reserve($job->channel);

        if (! $reservation->granted) {
            $job->release(max(1, (int) ceil($reservation->waitMilliseconds / 1000)));

            return;
        }

        if ($reservation->waitMilliseconds > 0) {
            Sleep::for($reservation->waitMilliseconds)->milliseconds();
        }

        $next($job);
    }
}
