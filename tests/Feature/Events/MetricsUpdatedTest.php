<?php

use App\Data\MetricsSnapshotData;
use App\Events\MetricsUpdated;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

/**
 * Build a small snapshot with one queue, one window and one circuit.
 */
function metricsUpdatedSnapshot(): MetricsSnapshotData
{
    return new MetricsSnapshotData(
        generatedAt: CarbonImmutable::parse('2026-11-14 12:00:00.123456'),
        queues: [['queue' => 'notifications-high', 'pending' => 4, 'delayed' => 1, 'reserved' => 2, 'oldest_pending_age_seconds' => 9]],
        inFlight: ['waiting' => 0, 'scheduled' => 1, 'queued' => 4, 'processing' => 2],
        windows: [[
            'window' => '5m',
            'seconds' => 300,
            'attempts' => ['total' => 3, 'accepted' => 2, 'retryable_failure' => 1, 'permanent_failure' => 0],
            'notifications' => ['sent' => 2, 'failed' => 0],
            'success_rate' => 1.0,
            'latency_ms' => [
                'provider' => ['p50' => 120.0, 'p95' => 180.5, 'p99' => 199.1],
                'end_to_end' => ['p50' => 900.0, 'p95' => 1500.0, 'p99' => 1580.0],
            ],
        ]],
        circuits: ['sms' => 'open', 'email' => 'closed', 'push' => 'closed'],
    );
}

test('broadcasts the snapshot to the operators as metrics.updated', function () {
    $event = new MetricsUpdated(metricsUpdatedSnapshot());

    expect($event->broadcastOn()[0]->name)->toBe('private-operators');
    expect($event->broadcastAs())->toBe('metrics.updated');
    expect($event->broadcastWith())->toBe(['metrics' => [
        'generated_at' => '2026-11-14T12:00:00.123456Z',
        'queues' => [['queue' => 'notifications-high', 'pending' => 4, 'delayed' => 1, 'reserved' => 2, 'oldest_pending_age_seconds' => 9]],
        'notifications_in_flight' => ['waiting' => 0, 'scheduled' => 1, 'queued' => 4, 'processing' => 2],
        'windows' => [[
            'window' => '5m',
            'seconds' => 300,
            'attempts' => ['total' => 3, 'accepted' => 2, 'retryable_failure' => 1, 'permanent_failure' => 0],
            'notifications' => ['sent' => 2, 'failed' => 0],
            'success_rate' => 1.0,
            'latency_ms' => [
                'provider' => ['p50' => 120.0, 'p95' => 180.5, 'p99' => 199.1],
                'end_to_end' => ['p50' => 900.0, 'p95' => 1500.0, 'p99' => 1580.0],
            ],
        ]],
        'circuits' => ['sms' => 'open', 'email' => 'closed', 'push' => 'closed'],
    ]]);
});

test('broadcasts at once from the process that collected the snapshot, without a queue', function () {
    Queue::fake();
    Bus::fake([BroadcastEvent::class]);

    MetricsUpdated::dispatch(metricsUpdatedSnapshot());

    Bus::assertDispatched(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof MetricsUpdated);
    Queue::assertNothingPushed();
});
