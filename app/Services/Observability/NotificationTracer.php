<?php

namespace App\Services\Observability;

use App\Data\DeliveryResultData;
use App\Enums\DeliveryOutcome;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use Closure;
use Illuminate\Support\Facades\Context as LogContext;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;

/**
 * Keeps every delivery attempt of a notification in one trace.
 *
 * A notification remembers the span its attempts are traced under: the request
 * that created it, or for a notification of a batch a short span of its own that
 * links to the batch request, so a batch of 1000 yields 1000 small traces rather
 * than one huge one. Each attempt then runs in a notification.deliver span under
 * that span, however much later and from whichever job it runs; the provider call
 * and the queries of the attempt become its children.
 *
 * Jobs that make no attempt (waiting for their time, a send slot or a closed
 * circuit) add nothing to the notification's trace. While tracing is off, every
 * span is a no-op and no ids are stored.
 */
final readonly class NotificationTracer
{
    public function __construct(private TracerInterface $tracer) {}

    /**
     * Get the span of the current request, for the notifications it creates one by one.
     */
    public function currentSpan(): ?SpanContextInterface
    {
        return $this->recorded(Span::getCurrent()->getContext());
    }

    /**
     * Start the own trace of a new notification of a batch, linked to the current request.
     */
    public function startNotificationTrace(string $notificationId, NotificationChannel $channel, string $batchId): ?SpanContextInterface
    {
        $builder = $this->tracer->spanBuilder('notification.accepted')
            ->setParent(false)
            ->setSpanKind(SpanKind::KIND_PRODUCER)
            ->setAttributes([
                'notification.id' => $notificationId,
                'notification.channel' => $channel->value,
                'notification.batch_id' => $batchId,
            ]);

        $request = $this->currentSpan();

        if ($request !== null) {
            $builder->addLink($request);
        }

        $span = $builder->startSpan();
        $span->end();

        return $this->recorded($span->getContext());
    }

    /**
     * Run one delivery attempt in a notification.deliver span of the notification's trace.
     *
     * Log entries of the attempt carry that trace's id, so they lead to the attempt in
     * the tracing UI. A notification without a stored span (created while tracing was
     * off) is traced under the job instead.
     *
     * @param  Closure(): DeliveryResultData  $attempt
     */
    public function traceAttempt(Notification $notification, Closure $attempt): DeliveryResultData
    {
        $span = $this->tracer->spanBuilder('notification.deliver')
            ->setParent($this->parentOf($notification))
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttributes([
                'notification.id' => $notification->id,
                'notification.channel' => $notification->channel->value,
                'notification.priority' => $notification->priority->value,
                'notification.attempt' => $notification->attempts,
            ])
            ->startSpan();

        $scope = $span->activate();
        $spanContext = $span->getContext();

        try {
            $result = LogContext::scope(
                $attempt,
                $spanContext->isValid() ? ['trace_id' => $spanContext->getTraceId()] : [],
            );

            $span->setAttribute('notification.outcome', $result->outcome->value);

            if ($result->outcome !== DeliveryOutcome::Sent) {
                $span->setStatus(StatusCode::STATUS_ERROR, $notification->last_error);
            }

            return $result;
        } catch (Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Run the callback without recording the spans that instrumentation starts inside it.
     *
     * Laravel's queue instrumentation records a span for every job it pushes, which
     * made pushing a batch of 1000 notifications take about a second longer and
     * filled the request's trace with 1000 spans. Inside the callback the current
     * span is a copy of the current one marked as not sampled: the spans started
     * under it are not recorded, and the jobs still carry the request's trace and
     * span ids to link to.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function withoutRecordingSpans(Closure $callback): mixed
    {
        $current = Span::getCurrent()->getContext();

        if (! $current->isValid() || ! $current->isSampled()) {
            return $callback();
        }

        $scope = Span::wrap(SpanContext::create($current->getTraceId(), $current->getSpanId(), TraceFlags::DEFAULT, $current->getTraceState()))
            ->activate();

        try {
            return $callback();
        } finally {
            $scope->detach();
        }
    }

    /**
     * Get the context the notification's attempts are traced under.
     */
    private function parentOf(Notification $notification): ?ContextInterface
    {
        if ($notification->trace_id === null || $notification->span_id === null) {
            return null;
        }

        $stored = SpanContext::createFromRemoteParent($notification->trace_id, $notification->span_id, TraceFlags::SAMPLED);

        return $stored->isValid() ? Span::wrap($stored)->storeInContext(Context::getCurrent()) : null;
    }

    /**
     * Keep a span context only when its span is recorded, as only then can attempts join its trace.
     */
    private function recorded(SpanContextInterface $spanContext): ?SpanContextInterface
    {
        return $spanContext->isValid() && $spanContext->isSampled() ? $spanContext : null;
    }
}
