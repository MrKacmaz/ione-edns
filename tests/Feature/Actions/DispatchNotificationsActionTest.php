<?php

use App\Actions\DispatchNotificationsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

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
