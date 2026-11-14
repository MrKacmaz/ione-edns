<?php

use App\Actions\CreateNotificationsAction;
use App\Data\NewNotificationData;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('returns the notifications in request order and flags only the new ones as created', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['content' => 'Existing']);

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'First'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Second', 'idempotency_key' => 'order-1001-shipped'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Third', 'idempotency_key' => 'order-1002-shipped'])),
    ]);

    expect($notifications->pluck('content')->all())->toBe(['First', 'Existing', 'Third']);
    expect($notifications->map(fn (Notification $notification): bool => $notification->wasRecentlyCreated)->all())->toBe([true, false, true]);
});

test('returns the existing notification for a reused idempotency key without changing or queueing it', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    $existing = Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['content' => 'Original text']);

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'A different text', 'idempotency_key' => 'order-1001-shipped'])),
    ]);

    expect($notifications->sole())
        ->id->toBe($existing->id)
        ->content->toBe('Original text')
        ->wasRecentlyCreated->toBeFalse();
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertNothingPushed();
});

test('creates a notification when only another user has used the idempotency key', function () {
    Queue::fake([SendNotificationJob::class]);
    $otherUsersNotification = Notification::factory()->withIdempotencyKey('order-1001-shipped')->create();
    $user = User::factory()->create();

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['idempotency_key' => 'order-1001-shipped'])),
    ]);

    expect($notifications->sole())
        ->id->not->toBe($otherUsersNotification->id)
        ->user_id->toBe($user->id)
        ->wasRecentlyCreated->toBeTrue();
    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notifications->sole()->id);
});

test('queues the new notifications that are due and holds those scheduled further ahead', function () {
    $this->freezeSecond();
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();

    [$immediate, $dueWithinAMinute, $dueLater] = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload()),
        NewNotificationData::fromValidated(smsNotificationPayload(['scheduled_at' => now()->addSeconds(30)->toIso8601String()])),
        NewNotificationData::fromValidated(smsNotificationPayload(['scheduled_at' => now()->addHours(2)->toIso8601String()])),
    ])->all();

    $this->assertDatabaseHas('notifications', ['id' => $immediate->id, 'status' => 'queued']);
    $this->assertDatabaseHas('notifications', ['id' => $dueWithinAMinute->id, 'status' => 'queued']);
    $this->assertDatabaseHas('notifications', ['id' => $dueLater->id, 'status' => 'scheduled']);
    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->all())
        ->toEqualCanonicalizing([$immediate->id, $dueWithinAMinute->id]);
});

test('holds the new notifications as waiting or scheduled and queues none until the surrounding transaction commits', function () {
    $this->freezeSecond();
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    $statusesBeforeCommit = [];
    $jobsPushedBeforeCommit = null;

    DB::transaction(function () use ($user, &$statusesBeforeCommit, &$jobsPushedBeforeCommit): void {
        $notifications = app(CreateNotificationsAction::class)->handle($user, [
            NewNotificationData::fromValidated(smsNotificationPayload()),
            NewNotificationData::fromValidated(smsNotificationPayload(['scheduled_at' => now()->addSeconds(30)->toIso8601String()])),
        ]);

        $statusesBeforeCommit = $notifications->map(fn (Notification $notification): string => $notification->fresh()->status->value)->all();
        $jobsPushedBeforeCommit = Queue::pushed(SendNotificationJob::class)->count();
    });

    expect($statusesBeforeCommit)->toBe(['waiting', 'scheduled']);
    expect($jobsPushedBeforeCommit)->toBe(0);
    Queue::assertPushedTimes(SendNotificationJob::class, 2);
});

test('stores the template and the request\'s correlation id with each new notification', function () {
    Queue::fake([SendNotificationJob::class]);
    Context::add('correlation_id', '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10');
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->for($user)->create();

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Hi Ada, your order 1 has shipped.', 'template_id' => $template->id])),
    ]);

    $this->assertDatabaseHas('notifications', [
        'id' => $notifications->sole()->id,
        'template_id' => $template->id,
        'correlation_id' => '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10',
        'content' => 'Hi Ada, your order 1 has shipped.',
    ]);
});

test('creates a batch for the user with every new notification, while a reused key keeps its original batch', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('campaign-42-user-7')->create();

    ['batch' => $batch, 'notifications' => $notifications] = app(CreateNotificationsAction::class)->handleBatch($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['idempotency_key' => 'campaign-42-user-7'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['idempotency_key' => 'campaign-42-user-8'])),
    ]);

    $this->assertDatabaseHas('notification_batches', ['id' => $batch->id, 'user_id' => $user->id]);
    expect($notifications->pluck('batch_id')->all())->toBe([null, $batch->id]);
});

test('creates every notification when the request needs more than one insert statement', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    $contents = array_map(fn (int $position): string => "Notification {$position}", range(1, 501));

    $notifications = app(CreateNotificationsAction::class)->handle($user, array_map(
        fn (string $content): NewNotificationData => NewNotificationData::fromValidated(smsNotificationPayload(['content' => $content])),
        $contents,
    ));

    expect($notifications->pluck('content')->all())->toBe($contents);
    $this->assertDatabaseCount('notifications', 501);
    Queue::assertPushedTimes(SendNotificationJob::class, 501);
});

test('resolves an idempotency key that a concurrent request inserted first to that request\'s notification', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    $concurrentNotification = null;
    $hasInsertedConcurrently = false;
    // Another request inserts the same key after this request looked for existing keys,
    // just before this request's own insert runs.
    DB::beforeExecuting(function (string $query) use ($user, &$concurrentNotification, &$hasInsertedConcurrently): void {
        if ($hasInsertedConcurrently || ! str_contains($query, 'on conflict do nothing')) {
            return;
        }

        $hasInsertedConcurrently = true;
        $concurrentNotification = Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['content' => 'From the concurrent request']);
    });

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'From this request', 'idempotency_key' => 'order-1001-shipped'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Without a key'])),
    ]);

    expect($notifications[0])
        ->id->toBe($concurrentNotification->id)
        ->content->toBe('From the concurrent request')
        ->wasRecentlyCreated->toBeFalse();
    expect($notifications[1])
        ->content->toBe('Without a key')
        ->wasRecentlyCreated->toBeTrue();
    $this->assertDatabaseCount('notifications', 2);
    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->all())
        ->toBe([$notifications[1]->id]);
});
