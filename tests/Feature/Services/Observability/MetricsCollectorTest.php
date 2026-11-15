<?php

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Observability\MetricsCollector;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCircuitBreaker;

/**
 * The collector reads the real Redis queues, so each test starts and ends with empty notification queues.
 */
function clearNotificationQueues(): void
{
    foreach (NotificationChannel::cases() as $channel) {
        foreach (NotificationPriority::queuesFor($channel) as $queue) {
            Queue::connection('redis')->clear($queue);
        }
    }
}

beforeEach(fn () => clearNotificationQueues());

afterEach(fn () => clearNotificationQueues());

test('stamps the snapshot with the moment it was collected', function () {
    $this->travelTo('2026-11-14 12:00:00.250000');

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->generatedAt->format('Y-m-d H:i:s.u'))->toBe('2026-11-14 12:00:00.250000');
});

test('reports pending, delayed and reserved jobs and the age of the oldest pending job per channel and priority queue', function () {
    $this->travelTo('2026-11-14 12:00:00');
    $redis = Queue::connection('redis');
    $redis->push(new SendNotificationJob('first', NotificationChannel::Sms), '', 'notifications-sms-normal');
    $this->travel(30)->seconds();
    $redis->push(new SendNotificationJob('second', NotificationChannel::Sms), '', 'notifications-sms-normal');
    $redis->later(60, new SendNotificationJob('scheduled', NotificationChannel::Sms), '', 'notifications-sms-normal');
    $redis->push(new SendNotificationJob('claimed', NotificationChannel::Email), '', 'notifications-email-high');
    $redis->pop('notifications-email-high');
    $this->travel(15)->seconds();

    $snapshot = app(MetricsCollector::class)->collect();

    $idle = ['pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age_seconds' => null];
    expect($snapshot->queues)->toBe([
        ['queue' => 'notifications-sms-high', ...$idle],
        ['queue' => 'notifications-sms-normal', 'pending' => 2, 'delayed' => 1, 'reserved' => 0, 'oldest_pending_age_seconds' => 45],
        ['queue' => 'notifications-sms-low', ...$idle],
        ['queue' => 'notifications-email-high', 'pending' => 0, 'delayed' => 0, 'reserved' => 1, 'oldest_pending_age_seconds' => null],
        ['queue' => 'notifications-email-normal', ...$idle],
        ['queue' => 'notifications-email-low', ...$idle],
        ['queue' => 'notifications-push-high', ...$idle],
        ['queue' => 'notifications-push-normal', ...$idle],
        ['queue' => 'notifications-push-low', ...$idle],
    ]);
});

test('never reports a negative age for a job stamped by a host whose clock runs ahead', function () {
    $this->travelTo('2026-11-14 12:00:10');
    Queue::connection('redis')->push(new SendNotificationJob('early', NotificationChannel::Push), '', 'notifications-push-low');
    $this->travelTo('2026-11-14 12:00:00');

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->queues[8])->toBe(['queue' => 'notifications-push-low', 'pending' => 1, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age_seconds' => 0]);
});

test('counts the notifications in every unfinished status and leaves out finished ones', function () {
    Notification::factory()->create();
    Notification::factory()->scheduled()->count(2)->create();
    Notification::factory()->processing()->create();
    Notification::factory()->sent()->create();
    Notification::factory()->failed()->create();
    Notification::factory()->cancelled()->create();

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->inFlight)->toBe(['waiting' => 1, 'scheduled' => 2, 'queued' => 0, 'processing' => 1]);
});

test('counts attempts and finished notifications from the first second of each window on', function () {
    $this->travelTo('2026-11-14 12:00:00');
    NotificationAttempt::factory()->create(['created_at' => now()->subSeconds(300)]);
    NotificationAttempt::factory()->create(['created_at' => now()->subSeconds(301)]);
    NotificationAttempt::factory()->retryableFailure()->create(['created_at' => now()->subSeconds(10)]);
    NotificationAttempt::factory()->permanentFailure()->create(['created_at' => now()->subSeconds(3600)]);
    NotificationAttempt::factory()->create(['created_at' => now()->subSeconds(3601)]);
    Notification::factory()->failed()->create(['failed_at' => now()->subSeconds(300)]);
    Notification::factory()->failed()->create(['failed_at' => now()->subSeconds(301)]);
    Notification::factory()->failed()->create(['failed_at' => now()->subSeconds(3601)]);

    [$fiveMinutes, $oneHour] = app(MetricsCollector::class)->collect()->windows;

    expect($fiveMinutes)
        ->window->toBe('5m')
        ->seconds->toBe(300)
        ->attempts->toBe(['total' => 2, 'accepted' => 1, 'retryable_failure' => 1, 'permanent_failure' => 0])
        ->notifications->toBe(['sent' => 1, 'failed' => 1]);
    expect($oneHour)
        ->window->toBe('1h')
        ->seconds->toBe(3600)
        ->attempts->toBe(['total' => 4, 'accepted' => 2, 'retryable_failure' => 1, 'permanent_failure' => 1])
        ->notifications->toBe(['sent' => 2, 'failed' => 2]);
});

test('reports the share of finished notifications that were sent, ignoring attempts that will be retried', function (int $sent, int $failed, ?float $successRate) {
    $this->travelTo('2026-11-14 12:00:00');
    NotificationAttempt::factory()->count($sent)->create();
    Notification::factory()->failed()->count($failed)->create();
    NotificationAttempt::factory()->retryableFailure()->create();

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->windows[0]['success_rate'])->toBe($successRate);
})->with([
    'two sent and one failed' => [2, 1, 0.6667],
    'only sent' => [3, 0, 1.0],
    'only failed' => [0, 2, 0.0],
    'nothing finished' => [0, 0, null],
]);

test('reports provider latency percentiles over every attempt in the window, failed ones included', function () {
    $this->travelTo('2026-11-14 12:00:00');
    foreach ([100, 200, 300] as $durationMilliseconds) {
        NotificationAttempt::factory()->create(['duration_ms' => $durationMilliseconds]);
    }
    NotificationAttempt::factory()->retryableFailure()->create(['duration_ms' => 1002]);
    NotificationAttempt::factory()->create(['duration_ms' => 5000, 'created_at' => now()->subMinutes(6)]);

    [$fiveMinutes, $oneHour] = app(MetricsCollector::class)->collect()->windows;

    expect($fiveMinutes['latency_ms']['provider'])->toBe(['p50' => 250.0, 'p95' => 896.7, 'p99' => 980.9]);
    expect($oneHour['latency_ms']['provider'])->toBe(['p50' => 300.0, 'p95' => 4200.4, 'p99' => 4840.1]);
});

test('measures end-to-end latency from creation to the accepted attempt and leaves out failed attempts', function () {
    $this->travelTo('2026-11-14 12:00:00');
    $first = Notification::factory()->sent()->create(['created_at' => now()->subSeconds(2)]);
    NotificationAttempt::factory()->for($first)->create();
    $second = Notification::factory()->sent()->create(['created_at' => now()->subSeconds(4)]);
    NotificationAttempt::factory()->for($second)->create();
    $retried = Notification::factory()->queued()->create(['created_at' => now()->subMinute()]);
    NotificationAttempt::factory()->for($retried)->retryableFailure()->create();

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->windows[0]['latency_ms']['end_to_end'])->toBe(['p50' => 3000.0, 'p95' => 3900.0, 'p99' => 3980.0]);
});

test('measures the end-to-end latency of a scheduled notification from its delivery time', function () {
    $this->travelTo('2026-11-14 12:00:00');
    $notification = Notification::factory()->sent()->create([
        'created_at' => now()->subDay(),
        'scheduled_at' => now()->subSeconds(3),
    ]);
    NotificationAttempt::factory()->for($notification)->create();

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->windows[0]['latency_ms']['end_to_end'])->toBe(['p50' => 3000.0, 'p95' => 3000.0, 'p99' => 3000.0]);
});

test('reports no latency percentiles for a window without attempts', function () {
    $this->travelTo('2026-11-14 12:00:00');
    NotificationAttempt::factory()->create(['created_at' => now()->subMinutes(10)]);

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->windows[0]['latency_ms'])->toBe([
        'provider' => ['p50' => null, 'p95' => null, 'p99' => null],
        'end_to_end' => ['p50' => null, 'p95' => null, 'p99' => null],
    ]);
});

test('reports the delivery circuit state of every channel', function () {
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: [
        'email' => CircuitState::Open,
        'push' => CircuitState::HalfOpen,
    ]));

    $snapshot = app(MetricsCollector::class)->collect();

    expect($snapshot->circuits)->toBe(['sms' => 'closed', 'email' => 'open', 'push' => 'half_open']);
});

test('shares one snapshot between callers until the cache period ends', function () {
    config(['notifications.metrics.cache_seconds' => 5]);
    $this->freezeSecond();
    Notification::factory()->queued()->create();
    $first = app(MetricsCollector::class)->snapshot();
    Notification::factory()->queued()->create();

    $withinPeriod = app(MetricsCollector::class)->snapshot();
    $this->travel(6)->seconds();
    $afterPeriod = app(MetricsCollector::class)->snapshot();

    expect($withinPeriod->inFlight['queued'])->toBe(1)
        ->and($withinPeriod->generatedAt->equalTo($first->generatedAt))->toBeTrue()
        ->and($afterPeriod->inFlight['queued'])->toBe(2);
});

test('reports the current circuit states with a shared snapshot, so a circuit that has just opened is not shown closed again', function () {
    config(['notifications.metrics.cache_seconds' => 5]);
    $this->freezeSecond();
    $first = app(MetricsCollector::class)->snapshot();
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: ['sms' => CircuitState::Open]));

    $withinPeriod = app(MetricsCollector::class)->snapshot();

    expect($withinPeriod->generatedAt->equalTo($first->generatedAt))->toBeTrue()
        ->and($first->circuits['sms'])->toBe('closed')
        ->and($withinPeriod->circuits)->toBe(['sms' => 'open', 'email' => 'closed', 'push' => 'closed']);
});

test('reads the shared snapshot back from a cache that serializes its values, as Redis does', function () {
    config(['notifications.metrics.cache_seconds' => 5]);
    $this->travelTo('2026-11-14 12:00:00.250000');
    $cache = new Repository(new ArrayStore(serializesValues: true, serializableClasses: config('cache.serializable_classes')));
    $collector = app()->make(MetricsCollector::class, ['cache' => $cache]);
    Notification::factory()->queued()->create();
    $collector->snapshot();

    $cached = $collector->snapshot();

    expect($cached->inFlight['queued'])->toBe(1)
        ->and($cached->generatedAt->format('Y-m-d H:i:s.u'))->toBe('2026-11-14 12:00:00.250000');
});

test('collects afresh on every call when caching is turned off', function () {
    config(['notifications.metrics.cache_seconds' => 0]);
    app(MetricsCollector::class)->snapshot();
    Notification::factory()->queued()->create();

    expect(app(MetricsCollector::class)->snapshot()->inFlight['queued'])->toBe(1);
});
