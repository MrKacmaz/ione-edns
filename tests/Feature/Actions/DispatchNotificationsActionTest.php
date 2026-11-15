<?php

use App\Actions\DispatchNotificationsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Testing\Fakes\QueueFake;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;

test('pushes each notification onto the queue of its channel and priority and marks it queued', function () {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14 10:00:00.250000');
    $notifications = Notification::factory()->count(3)->sequence(
        ['channel' => NotificationChannel::Sms, 'priority' => NotificationPriority::High],
        ['channel' => NotificationChannel::Email, 'priority' => NotificationPriority::Normal],
        ['channel' => NotificationChannel::Push, 'priority' => NotificationPriority::Low],
    )->create();

    app(DispatchNotificationsAction::class)->handle($notifications);

    Queue::assertPushedOn('notifications-sms-high', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notifications[0]->id);
    Queue::assertPushedOn('notifications-email-normal', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notifications[1]->id);
    Queue::assertPushedOn('notifications-push-low', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notifications[2]->id);
    foreach ($notifications as $notification) {
        expect($notification->status)->toBe(NotificationStatus::Queued)
            ->and($notification->refresh()->status)->toBe(NotificationStatus::Queued)
            ->and($notification->queued_at?->toJSON())->toBe('2026-11-14T10:00:00.250000Z');
    }
});

test('carries the channel on the job so the rate limiter needs no database read', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->push()->create();

    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->channel === $notification->channel);
});

test('delays a notification scheduled for the future until its delivery time', function (string $scheduledAt, string $expectedDelayUntil) {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14 10:00:00.000000');
    $notification = Notification::factory()->scheduled()->create(['scheduled_at' => $scheduledAt]);

    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->delay instanceof DateTimeInterface
        && $job->delay->format('Y-m-d H:i:s.u') === $expectedDelayUntil);
    expect($notification->refresh()->status)->toBe(NotificationStatus::Queued);
})->with([
    'on a whole second' => ['2026-11-14 10:00:40.000000', '2026-11-14 10:00:40.000000'],
    // The queue keeps whole seconds; rounding down would run the job 250 ms early.
    'within a second' => ['2026-11-14 10:00:40.250000', '2026-11-14 10:00:41.000000'],
]);

test('pushes an overdue scheduled notification without a delay', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->scheduled()->create(['scheduled_at' => now()->subMinutes(5)]);

    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->delay === null);
});

test('does not mark a notification queued that was cancelled in the meantime', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(40)]);
    Notification::query()->whereKey($notification->id)->update(['status' => NotificationStatus::Cancelled]);

    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));

    expect($notification->status)->toBe(NotificationStatus::Cancelled)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Cancelled);
});

test('still marks the other notifications queued when one was cancelled in the meantime', function () {
    Queue::fake([SendNotificationJob::class]);
    [$cancelled, $waiting] = Notification::factory()->count(2)->create();
    Notification::query()->whereKey($cancelled->id)->update(['status' => NotificationStatus::Cancelled]);

    app(DispatchNotificationsAction::class)->handle(new Collection([$cancelled, $waiting]));

    expect($cancelled->status)->toBe(NotificationStatus::Cancelled)
        ->and($waiting->status)->toBe(NotificationStatus::Queued);
    Queue::assertPushed(SendNotificationJob::class, 2);
});

test('does nothing for an empty collection', function () {
    Queue::fake();

    app(DispatchNotificationsAction::class)->handle(new Collection);

    Queue::assertNothingPushed();
});

test('leaves the notifications waiting when the queue refuses their jobs', function () {
    Queue::swap(new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null): never
        {
            throw new RuntimeException('The queue is unavailable.');
        }
    });
    $notification = Notification::factory()->create();

    expect(fn () => app(DispatchNotificationsAction::class)->handle(new Collection([$notification])))
        ->toThrow(RuntimeException::class, 'The queue is unavailable.');
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Waiting)
        ->queued_at->toBeNull();
});

test('pushes the jobs of each queue together, in one bulk push', function () {
    $queue = new class(app()) extends QueueFake
    {
        /** @var array<string, int> */
        public array $bulkPushes = [];

        public function bulk($jobs, $data = '', $queue = null): void
        {
            $this->bulkPushes[$queue] = count($jobs);

            parent::bulk($jobs, $data, $queue);
        }
    };
    Queue::swap($queue);
    $notifications = Notification::factory()->count(5)->sequence(
        ['channel' => NotificationChannel::Sms, 'priority' => NotificationPriority::High],
        ['channel' => NotificationChannel::Sms, 'priority' => NotificationPriority::High],
        ['channel' => NotificationChannel::Sms, 'priority' => NotificationPriority::High],
        ['channel' => NotificationChannel::Email, 'priority' => NotificationPriority::Normal],
        ['channel' => NotificationChannel::Email, 'priority' => NotificationPriority::Normal],
    )->create();

    app(DispatchNotificationsAction::class)->handle($notifications);

    expect($queue->bulkPushes)->toBe(['notifications-sms-high' => 3, 'notifications-email-normal' => 2]);
});

test('pushes the jobs without recording a span for each of them, linked to the current span', function () {
    $queue = new class(app()) extends QueueFake
    {
        public ?SpanContextInterface $spanWhilePushing = null;

        public function bulk($jobs, $data = '', $queue = null): void
        {
            $this->spanWhilePushing = Span::getCurrent()->getContext();

            parent::bulk($jobs, $data, $queue);
        }
    };
    Queue::swap($queue);
    $scope = Span::wrap(SpanContext::create('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', TraceFlags::SAMPLED))->activate();

    try {
        app(DispatchNotificationsAction::class)->handle(new Collection([Notification::factory()->create()]));
    } finally {
        $scope->detach();
    }

    expect($queue->spanWhilePushing->getSpanId())->toBe('00f067aa0ba902b7')
        ->and($queue->spanWhilePushing->isSampled())->toBeFalse();
});

test('gives each job its notification\'s correlation id, whoever pushes it', function () {
    Queue::fake([SendNotificationJob::class]);
    Context::add('correlation_id', 'f6b2a4e0-0d1c-4f53-9c1e-2a7b8c9d0e1f');
    $notifications = Notification::factory()->count(2)->sequence(
        ['correlation_id' => '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10'],
        ['correlation_id' => '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e11'],
    )->create();

    app(DispatchNotificationsAction::class)->handle($notifications);

    foreach ($notifications as $notification) {
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notification->id
            && $job->correlationId === $notification->correlation_id);
    }
    expect(Context::all())->toBe(['correlation_id' => 'f6b2a4e0-0d1c-4f53-9c1e-2a7b8c9d0e1f']);
});

test('pushes the jobs into Redis in one pipeline, the scheduled ones onto the delayed queue', function () {
    config(['queue.default' => 'redis']);
    $redis = Redis::connection();
    $redis->del('queues:notifications-sms-high', 'queues:notifications-sms-high:delayed');
    $this->travelTo('2026-11-14 10:00:00');
    $notifications = Notification::factory()->count(3)->sequence(
        ['priority' => NotificationPriority::High],
        ['priority' => NotificationPriority::High],
        ['priority' => NotificationPriority::High, 'status' => NotificationStatus::Scheduled, 'scheduled_at' => '2026-11-14 10:00:40'],
    )->create();

    try {
        app(DispatchNotificationsAction::class)->handle($notifications);

        $ready = array_map(fn (string $payload): string => unserialize(json_decode($payload, true)['data']['command'])->notificationId, $redis->lrange('queues:notifications-sms-high', 0, -1));
        $delayed = $redis->zrange('queues:notifications-sms-high:delayed', 0, -1, ['withscores' => true]);

        expect($ready)->toBe([$notifications[0]->id, $notifications[1]->id])
            ->and(array_values($delayed))->toBe([(float) now()->addSeconds(40)->getTimestamp()]);
    } finally {
        $redis->del('queues:notifications-sms-high', 'queues:notifications-sms-high:delayed');
    }
});
