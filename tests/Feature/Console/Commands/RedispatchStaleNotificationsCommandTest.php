<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\Delivery\DeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;

beforeEach(function () {
    config([
        'notifications.delivery.stale_after_seconds.waiting' => 120,
        'notifications.delivery.stale_after_seconds.processing' => 300,
        'notifications.delivery.max_attempts' => 5,
    ]);
});

test('pushes notifications waiting longer than the threshold onto their queue again', function () {
    Queue::fake([SendNotificationJob::class]);
    $stale = Notification::factory()->create(['created_at' => now()->subMinutes(3)]);
    $fresh = Notification::factory()->create(['created_at' => now()->subSeconds(30)]);

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $stale->id);
    expect($stale->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($fresh->refresh()->status)->toBe(NotificationStatus::Waiting);
});

test('puts notifications stuck in processing back in line', function () {
    Queue::fake([SendNotificationJob::class]);
    $stuck = Notification::factory()->processing()->create();
    $stuck->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
    $busy = Notification::factory()->processing()->create();

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 1);
    expect($stuck->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($busy->refresh()->status)->toBe(NotificationStatus::Processing);
});

test('leaves queued and finished notifications alone', function () {
    Queue::fake();
    Notification::factory()->queued()->create(['created_at' => now()->subHour()]);
    Notification::factory()->sent()->create(['created_at' => now()->subHour()]);

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('treats a waiting notification as stale only once the waiting threshold has passed', function () {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14T12:00:00Z');
    Notification::factory()->create(['created_at' => now()->subSeconds(120)]);
    $pastThreshold = Notification::factory()->create(['created_at' => now()->subSeconds(121)]);

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->values()->all())
        ->toBe([$pastThreshold->id]);
});

test('treats a processing notification as stuck only once the processing threshold has passed', function () {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14T12:00:00Z');
    $atThreshold = Notification::factory()->processing()->create();
    $atThreshold->forceFill(['updated_at' => now()->subSeconds(300)])->saveQuietly();
    $pastThreshold = Notification::factory()->processing()->create();
    $pastThreshold->forceFill(['updated_at' => now()->subSeconds(301)])->saveQuietly();

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->values()->all())
        ->toBe([$pastThreshold->id]);
    expect($atThreshold->refresh()->status)->toBe(NotificationStatus::Processing);
});

test('leaves scheduled notifications to the dispatch-due command', function () {
    Queue::fake();
    $scheduled = Notification::factory()->scheduled()->create(['created_at' => now()->subHour()]);

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($scheduled->refresh()->status)->toBe(NotificationStatus::Scheduled);
});

test('reports how many notifications it requeued and redispatched', function () {
    Queue::fake([SendNotificationJob::class]);
    Notification::factory()->create(['created_at' => now()->subMinutes(3)]);
    $stuck = Notification::factory()->processing()->create();
    $stuck->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

    $this->artisan('notifications:redispatch-stale')
        ->expectsOutput('Failed 0 stuck processing without attempts left; requeued 1 stuck processing, 0 lost queued and 1 stale waiting notifications.')
        ->assertSuccessful();
});

test('fails a notification stuck in processing after its last attempt instead of sending it again', function () {
    Queue::fake([SendNotificationJob::class]);
    Event::fake([NotificationStatusChanged::class]);
    $lastAttempt = Notification::factory()->processing()->create(['attempts' => 5]);
    $lastAttempt->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
    $attemptsLeft = Notification::factory()->processing()->create(['attempts' => 4]);
    $attemptsLeft->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

    $this->artisan('notifications:redispatch-stale')
        ->expectsOutput('Failed 1 stuck processing without attempts left; requeued 1 stuck processing, 0 lost queued and 0 stale waiting notifications.')
        ->assertSuccessful();

    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->all())
        ->toBe([$attemptsLeft->id]);
    expect($lastAttempt->refresh())
        ->status->toBe(NotificationStatus::Failed)
        ->attempts->toBe(5)
        ->last_error->toBe('The last delivery attempt stopped before its outcome was recorded; the provider may have received the notification.')
        ->failed_at->not->toBeNull();
    Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $lastAttempt->id && $event->notification['status'] === 'failed');
});

test('sends a notification whose outcome is never recorded at most as often as it has attempts', function () {
    Queue::fake([SendNotificationJob::class]);
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-1'], 202)]);
    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'insert into "notification_attempts"')) {
            throw new RuntimeException('The attempt cannot be stored.');
        }
    });
    $notification = Notification::factory()->queued()->create();

    for ($sweep = 1; $sweep <= 7; $sweep++) {
        try {
            app(DeliveryService::class)->deliver($notification->id);
        } catch (RuntimeException) {
            // The job would be retried and then skip the notification, which stays processing.
        }

        $this->travel(10)->minutes();
        $this->artisan('notifications:redispatch-stale')->assertSuccessful();
    }

    Http::assertSentCount(5);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Failed)
        ->attempts->toBe(5);
});

test('lets the pushed job deliver a notification its dead worker left processing', function () {
    Queue::fake([SendNotificationJob::class]);
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-2'], 202)]);
    $stuck = Notification::factory()->processing()->create();
    $stuck->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();
    $this->artisan('notifications:redispatch-stale')->assertSuccessful();
    $job = Queue::pushed(SendNotificationJob::class)->sole();

    $job->handle(app(DeliveryService::class));

    expect($stuck->refresh())
        ->status->toBe(NotificationStatus::Sent)
        ->attempts->toBe(2);
});

test('leaves a notification stuck in processing waiting when its job cannot be pushed, so the next sweep retries it', function () {
    Queue::swap(new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null): never
        {
            throw new RuntimeException('The queue is unavailable.');
        }
    });
    $stuck = Notification::factory()->processing()->create();
    $stuck->forceFill(['updated_at' => now()->subMinutes(10)])->saveQuietly();

    expect(fn () => $this->artisan('notifications:redispatch-stale')->run())
        ->toThrow(RuntimeException::class, 'The queue is unavailable.');
    expect($stuck->refresh()->status)->toBe(NotificationStatus::Waiting);
});

test('pushes a queued notification again once it has outlived any job, as its job was lost', function () {
    Queue::fake([SendNotificationJob::class]);
    config(['notifications.delivery.stale_after_seconds.queued' => 90_000]);
    $this->travelTo('2026-11-14T12:00:00Z');
    $lost = Notification::factory()->queued()->create();
    $lost->forceFill(['updated_at' => now()->subSeconds(90_001)])->saveQuietly();
    $stillWaitingForItsJob = Notification::factory()->queued()->create();
    $stillWaitingForItsJob->forceFill(['updated_at' => now()->subSeconds(90_000)])->saveQuietly();

    $this->artisan('notifications:redispatch-stale')->assertSuccessful();

    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->all())
        ->toBe([$lost->id]);
    expect($lost->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($stillWaitingForItsJob->refresh()->status)->toBe(NotificationStatus::Queued);
});

test('keeps the lost-job threshold above the time a delivery job lives, so only notifications without a job are pushed again', function () {
    $this->freezeTime();
    $jobLifetimeSeconds = now()->diffInSeconds((new SendNotificationJob('notification-id', NotificationChannel::Sms))->retryUntil());

    expect(config('notifications.delivery.stale_after_seconds.queued'))->toBeGreaterThan($jobLifetimeSeconds);
});
