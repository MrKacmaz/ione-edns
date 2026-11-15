<?php

use App\Data\DeliveryResultData;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Observability\NotificationTracer;
use Illuminate\Support\Facades\Context as LogContext;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use Tests\Support\InMemoryTracing;

const REQUEST_TRACE_ID = '4bf92f3577b34da6a3ce929d0e0e4736';
const REQUEST_SPAN_ID = '00f067aa0ba902b7';

/**
 * Run the callback while a span with the given context is the current one.
 */
function whileSpanIsCurrent(string $traceId, string $spanId, int $traceFlags, Closure $callback): mixed
{
    $scope = Span::wrap(SpanContext::create($traceId, $spanId, $traceFlags))->activate();

    try {
        return $callback();
    } finally {
        $scope->detach();
    }
}

describe('currentSpan', function () {
    test('returns the current request\'s span while it is recorded', function () {
        $span = whileSpanIsCurrent(REQUEST_TRACE_ID, REQUEST_SPAN_ID, TraceFlags::SAMPLED, fn () => app(NotificationTracer::class)->currentSpan());

        expect($span->getTraceId())->toBe(REQUEST_TRACE_ID)
            ->and($span->getSpanId())->toBe(REQUEST_SPAN_ID);
    });

    test('returns nothing while tracing is off or the request is not recorded', function () {
        $tracer = app(NotificationTracer::class);

        expect($tracer->currentSpan())->toBeNull()
            ->and(whileSpanIsCurrent(REQUEST_TRACE_ID, REQUEST_SPAN_ID, TraceFlags::DEFAULT, fn () => $tracer->currentSpan()))->toBeNull();
    });
});

describe('startNotificationTrace', function () {
    test('starts a trace of the notification\'s own, linked to the batch request', function () {
        $tracing = InMemoryTracing::bind($this->app);

        $span = whileSpanIsCurrent(REQUEST_TRACE_ID, REQUEST_SPAN_ID, TraceFlags::SAMPLED, fn () => app(NotificationTracer::class)
            ->startNotificationTrace('notification-1', NotificationChannel::Push, 'batch-1'));

        $accepted = $tracing->soleSpanNamed('notification.accepted');
        expect($span->getTraceId())->toBe($accepted->getContext()->getTraceId())->not->toBe(REQUEST_TRACE_ID)
            ->and($span->getSpanId())->toBe($accepted->getContext()->getSpanId())
            ->and($accepted->getParentSpanId())->toBe('0000000000000000')
            ->and($accepted->getKind())->toBe(SpanKind::KIND_PRODUCER)
            ->and($accepted->getAttributes()->get('notification.id'))->toBe('notification-1')
            ->and($accepted->getAttributes()->get('notification.channel'))->toBe('push')
            ->and($accepted->getAttributes()->get('notification.batch_id'))->toBe('batch-1')
            ->and($accepted->getLinks())->toHaveCount(1)
            ->and($accepted->getLinks()[0]->getSpanContext()->getSpanId())->toBe(REQUEST_SPAN_ID);
    });

    test('returns nothing while tracing is off', function () {
        expect(app(NotificationTracer::class)->startNotificationTrace('notification-1', NotificationChannel::Sms, 'batch-1'))->toBeNull();
    });
});

describe('traceAttempt', function () {
    test('runs the attempt in a span under the notification\'s stored span, with its trace id in the log context', function () {
        $tracing = InMemoryTracing::bind($this->app);
        $notification = Notification::factory()->sent()->make([
            'id' => 'notification-1',
            'priority' => 'high',
            'attempts' => 2,
            'trace_id' => REQUEST_TRACE_ID,
            'span_id' => REQUEST_SPAN_ID,
        ]);
        $seen = [];

        $result = app(NotificationTracer::class)->traceAttempt($notification, function () use (&$seen): DeliveryResultData {
            $seen = ['span' => Span::getCurrent()->getContext()->getSpanId(), 'trace_id' => LogContext::get('trace_id')];

            return DeliveryResultData::sent();
        });

        $deliver = $tracing->soleSpanNamed('notification.deliver');
        expect($result->outcome->value)->toBe('sent')
            ->and($deliver->getContext()->getTraceId())->toBe(REQUEST_TRACE_ID)
            ->and($deliver->getParentSpanId())->toBe(REQUEST_SPAN_ID)
            ->and($deliver->getKind())->toBe(SpanKind::KIND_CONSUMER)
            ->and($deliver->getStatus()->getCode())->toBe(StatusCode::STATUS_UNSET)
            ->and($deliver->getAttributes()->toArray())->toMatchArray([
                'notification.id' => 'notification-1',
                'notification.channel' => 'sms',
                'notification.priority' => 'high',
                'notification.attempt' => 2,
                'notification.outcome' => 'sent',
            ])
            ->and($seen)->toBe(['span' => $deliver->getContext()->getSpanId(), 'trace_id' => REQUEST_TRACE_ID])
            ->and(LogContext::get('trace_id'))->toBeNull()
            ->and(Span::getCurrent()->getContext()->isValid())->toBeFalse();
    });

    test('marks the span as an error when the attempt does not deliver the notification', function () {
        $tracing = InMemoryTracing::bind($this->app);
        $notification = Notification::factory()->failed()->make(['trace_id' => REQUEST_TRACE_ID, 'span_id' => REQUEST_SPAN_ID]);

        app(NotificationTracer::class)->traceAttempt($notification, fn (): DeliveryResultData => DeliveryResultData::retryScheduled(30));

        $deliver = $tracing->soleSpanNamed('notification.deliver');
        expect($deliver->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($deliver->getStatus()->getDescription())->toBe('Provider responded with HTTP 503.')
            ->and($deliver->getAttributes()->get('notification.outcome'))->toBe('retry_scheduled');
    });

    test('records an exception of the attempt, ends the span and lets the exception through', function () {
        $tracing = InMemoryTracing::bind($this->app);
        $notification = Notification::factory()->make(['trace_id' => REQUEST_TRACE_ID, 'span_id' => REQUEST_SPAN_ID]);

        expect(fn () => app(NotificationTracer::class)->traceAttempt($notification, fn () => throw new RuntimeException('Database is gone.')))
            ->toThrow(RuntimeException::class, 'Database is gone.');

        $deliver = $tracing->soleSpanNamed('notification.deliver');
        expect($deliver->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($deliver->getEvents()[0]->getName())->toBe('exception')
            ->and(Span::getCurrent()->getContext()->isValid())->toBeFalse();
    });

    test('traces a notification without a stored span under the current job', function () {
        $tracing = InMemoryTracing::bind($this->app);
        $notification = Notification::factory()->make(['trace_id' => null, 'span_id' => null]);

        whileSpanIsCurrent('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331', TraceFlags::SAMPLED, fn () => app(NotificationTracer::class)
            ->traceAttempt($notification, fn (): DeliveryResultData => DeliveryResultData::sent()));

        $deliver = $tracing->soleSpanNamed('notification.deliver');
        expect($deliver->getContext()->getTraceId())->toBe('0af7651916cd43dd8448eb211c80319c')
            ->and($deliver->getParentSpanId())->toBe('b7ad6b7169203331');
    });

    test('still logs the attempt under the stored trace while tracing is off', function () {
        $notification = Notification::factory()->make(['trace_id' => REQUEST_TRACE_ID, 'span_id' => REQUEST_SPAN_ID]);
        $loggedTraceId = null;

        $result = app(NotificationTracer::class)->traceAttempt($notification, function () use (&$loggedTraceId): DeliveryResultData {
            $loggedTraceId = LogContext::get('trace_id');

            return DeliveryResultData::sent();
        });

        expect($result->outcome->value)->toBe('sent')
            ->and($loggedTraceId)->toBe(REQUEST_TRACE_ID);
    });

    test('adds no trace id to the log of an untraced notification while tracing is off', function () {
        $notification = Notification::factory()->make(['trace_id' => null, 'span_id' => null]);

        app(NotificationTracer::class)->traceAttempt($notification, function (): DeliveryResultData {
            expect(LogContext::has('trace_id'))->toBeFalse();

            return DeliveryResultData::sent();
        });
    });
});

describe('withoutRecordingSpans', function () {
    test('records none of the spans started inside, while keeping the current trace and span ids', function () {
        $tracing = InMemoryTracing::bind($this->app);
        $seen = null;

        $result = whileSpanIsCurrent(REQUEST_TRACE_ID, REQUEST_SPAN_ID, TraceFlags::SAMPLED, function () use ($tracing, &$seen) {
            return app(NotificationTracer::class)->withoutRecordingSpans(function () use ($tracing, &$seen): string {
                $tracing->tracer()->spanBuilder('create notifications-sms-normal')->startSpan()->end();
                $seen = Span::getCurrent()->getContext();

                return 'pushed';
            });
        });

        expect($result)->toBe('pushed')
            ->and($tracing->spansNamed('create notifications-sms-normal'))->toBe([])
            ->and($seen->getTraceId())->toBe(REQUEST_TRACE_ID)
            ->and($seen->getSpanId())->toBe(REQUEST_SPAN_ID)
            ->and($seen->isSampled())->toBeFalse();
    });

    test('restores the current span afterwards', function () {
        $after = whileSpanIsCurrent(REQUEST_TRACE_ID, REQUEST_SPAN_ID, TraceFlags::SAMPLED, function () {
            app(NotificationTracer::class)->withoutRecordingSpans(fn () => null);

            return Span::getCurrent()->getContext();
        });

        expect($after->getSpanId())->toBe(REQUEST_SPAN_ID)
            ->and($after->isSampled())->toBeTrue();
    });

    test('just runs the callback while nothing is traced', function () {
        expect(app(NotificationTracer::class)->withoutRecordingSpans(fn () => 'pushed'))->toBe('pushed');
    });
});
