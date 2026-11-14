<?php

use App\Enums\NotificationChannel;
use App\Jobs\Middleware\AssignDeliveryTraceContext;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;

const DELIVERY_TRACE_ID = '0af7651916cd43dd8448eb211c80319c';

/**
 * Run the middleware inside a delivery span, and return the log context the job sees.
 *
 * @return array<string, mixed>
 */
function contextSeenByJobInDeliveryTrace(): array
{
    $scope = Span::wrap(SpanContext::create(DELIVERY_TRACE_ID, 'b7ad6b7169203331'))->activate();
    $seen = [];

    try {
        (new AssignDeliveryTraceContext)->handle(new SendNotificationJob('notification-id', NotificationChannel::Sms), function () use (&$seen): void {
            $seen = Context::all();
        });
    } finally {
        $scope->detach();
    }

    return $seen;
}

test('logs the job under the delivery\'s own trace and keeps the trace of the request that created it', function () {
    Context::add('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');

    $context = contextSeenByJobInDeliveryTrace();

    expect($context)
        ->trace_id->toBe(DELIVERY_TRACE_ID)
        ->request_trace_id->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

test('adds the delivery trace when the request was not traced', function () {
    $context = contextSeenByJobInDeliveryTrace();

    expect($context)->trace_id->toBe(DELIVERY_TRACE_ID)
        ->and($context)->not->toHaveKey('request_trace_id');
});

test('leaves the context alone when tracing is off', function () {
    Context::add('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
    $seen = [];

    (new AssignDeliveryTraceContext)->handle(new SendNotificationJob('notification-id', NotificationChannel::Sms), function () use (&$seen): void {
        $seen = Context::all();
    });

    expect($seen)->toBe(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736']);
});
