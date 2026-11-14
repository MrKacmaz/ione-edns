<?php

namespace App\Jobs\Middleware;

use App\Jobs\SendNotificationJob;
use Closure;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\Span;

/**
 * Points the job's log entries at the delivery's own trace.
 *
 * The context of the request that created the notification travels with the job,
 * including that request's trace id. Each delivery runs in its own trace, linked
 * to the request's (see SendNotificationJob), so without this every log entry of
 * the delivery would lead to the request's trace in Jaeger instead. The request's
 * trace id is kept as request_trace_id.
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
