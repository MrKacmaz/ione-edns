<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * Gives the job's log entries its notification's id and correlation id.
 *
 * Every entry of the job, also those before the delivery attempt such as a wait
 * for an open circuit, can then be found by either id, whether the job was pushed
 * by the request that created the notification, the stale sweep or the scheduler.
 * The job carries both ids itself, as jobs are pushed in bulk without a context
 * of their own.
 */
class AssignNotificationContext
{
    /**
     * Process the queued job.
     *
     * @param  Closure(SendNotificationJob): void  $next
     */
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        Context::add('notification_id', $job->notificationId);

        if ($job->correlationId !== null) {
            Context::add('correlation_id', $job->correlationId);
        }

        $next($job);
    }
}
