<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use Closure;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\Span;

/**
 * Points the job's log entries at the job's own trace.
 *
 * The context of the request that created the notification travels with the job,
 * including that request's trace id. Each job run has its own trace, linked to the
 * request's (see SendNotificationJob), so without this the job's log entries, such
 * as a wait for a closed circuit, would lead to the request's trace in Jaeger. The
 * request's trace id is kept as request_trace_id. Entries of the delivery attempt
 * itself carry the notification's trace (see NotificationTracer).
 */
class AssignDeliveryTraceContext
{
    /**
     * Process the queued job.
     *
     * @param  Closure(SendNotificationJob): void  $next
     */
    public function handle(SendNotificationJob $job, Closure $next): void
    {
        $spanContext = Span::getCurrent()->getContext();
        $requestTraceId = Context::get('trace_id');

        if ($spanContext->isValid() && $requestTraceId !== $spanContext->getTraceId()) {
            if (is_string($requestTraceId)) {
                Context::add('request_trace_id', $requestTraceId);
            }

            Context::add('trace_id', $spanContext->getTraceId());
        }

        $next($job);
    }
}
