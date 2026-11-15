<?php

use App\Actions\CreateNotificationsAction;
use App\Data\NewNotificationData;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Tests\Support\InMemoryTracing;

test('returns the notifications in request order and flags only the new ones as created', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['recipient' => '+905551234567', 'content' => 'Existing']);

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'First'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Existing', 'idempotency_key' => 'order-1001-shipped'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Third', 'idempotency_key' => 'order-1002-shipped'])),
    ]);

    expect($notifications->pluck('content')->all())->toBe(['First', 'Existing', 'Third']);
    expect($notifications->map(fn (Notification $notification): bool => $notification->wasRecentlyCreated)->all())->toBe([true, false, true]);
});

test('returns the existing notification for a reused idempotency key without changing or queueing it', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    $existing = Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['recipient' => '+905551234567', 'content' => 'Original text']);

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Original text', 'idempotency_key' => 'order-1001-shipped'])),
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

test('stores the template, the request\'s correlation id and the request\'s span with each new notification', function () {
    Queue::fake([SendNotificationJob::class]);
    Context::add('correlation_id', '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10');
    $user = User::factory()->create();
    $template = NotificationTemplate::factory()->for($user)->create();
    $scope = Span::wrap(SpanContext::create('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', TraceFlags::SAMPLED))->activate();

    try {
        $notifications = app(CreateNotificationsAction::class)->handle($user, [
            NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Hi Ada, your order 1 has shipped.', 'template_id' => $template->id])),
        ]);
    } finally {
        $scope->detach();
    }

    $this->assertDatabaseHas('notifications', [
        'id' => $notifications->sole()->id,
        'template_id' => $template->id,
        'correlation_id' => '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10',
        'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
        'span_id' => '00f067aa0ba902b7',
        'content' => 'Hi Ada, your order 1 has shipped.',
    ]);
});

test('starts a trace of its own for every new notification of a batch, linked to the request', function () {
    Queue::fake([SendNotificationJob::class]);
    $tracing = InMemoryTracing::bind($this->app);
    $user = User::factory()->create();
    $scope = Span::wrap(SpanContext::create('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', TraceFlags::SAMPLED))->activate();

    try {
        ['notifications' => $notifications] = app(CreateNotificationsAction::class)->handleBatch($user, [
            NewNotificationData::fromValidated(smsNotificationPayload()),
            NewNotificationData::fromValidated(smsNotificationPayload()),
        ]);
    } finally {
        $scope->detach();
    }

    $accepted = $tracing->spansNamed('notification.accepted');
    expect($accepted)->toHaveCount(2)
        ->and($notifications->pluck('trace_id')->all())->toBe(array_map(fn ($span) => $span->getContext()->getTraceId(), $accepted))
        ->and($notifications->pluck('span_id')->all())->toBe(array_map(fn ($span) => $span->getContext()->getSpanId(), $accepted))
        ->and($notifications->pluck('trace_id')->unique())->toHaveCount(2)
        ->and($notifications->pluck('trace_id'))->not->toContain('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($accepted[0]->getLinks()[0]->getSpanContext()->getTraceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

test('stores no trace while the request is not traced', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();

    $single = app(CreateNotificationsAction::class)->handle($user, [NewNotificationData::fromValidated(smsNotificationPayload())]);
    ['notifications' => $batched] = app(CreateNotificationsAction::class)->handleBatch($user, [NewNotificationData::fromValidated(smsNotificationPayload())]);

    expect($single->concat($batched)->map->only('trace_id', 'span_id')->all())
        ->toBe([['trace_id' => null, 'span_id' => null], ['trace_id' => null, 'span_id' => null]]);
});

test('creates a batch for the user with every new notification, while a reused key keeps its original batch', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('campaign-42-user-7')->create(['recipient' => '+905551234567', 'content' => 'Your order has shipped.']);

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
        $concurrentNotification = Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['recipient' => '+905551234567', 'content' => 'Sent twice']);
    });

    $notifications = app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Sent twice', 'idempotency_key' => 'order-1001-shipped'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Without a key'])),
    ]);

    expect($notifications[0])
        ->id->toBe($concurrentNotification->id)
        ->content->toBe('Sent twice')
        ->wasRecentlyCreated->toBeFalse();
    expect($notifications[1])
        ->content->toBe('Without a key')
        ->wasRecentlyCreated->toBeTrue();
    $this->assertDatabaseCount('notifications', 2);
    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->all())
        ->toBe([$notifications[1]->id]);
});

test('keeps the committed notifications waiting and reports the error when the queue cannot be reached', function () {
    Exceptions::fake();
    Queue::swap(new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null): never
        {
            throw new RuntimeException('Connection refused');
        }
    });
    $user = User::factory()->create();

    $notifications = app(CreateNotificationsAction::class)->handle($user, [NewNotificationData::fromValidated(smsNotificationPayload())]);

    expect($notifications->sole()->refresh()->status)->toBe(NotificationStatus::Waiting);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Connection refused');
});

test('refuses a reused idempotency key that comes with a different notification and creates nothing', function (array $difference) {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create([
        'recipient' => '+905551234567',
        'content' => 'Your order has shipped.',
    ]);

    $create = fn () => app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Without a key'])),
        NewNotificationData::fromValidated(smsNotificationPayload(['idempotency_key' => 'order-1001-shipped', ...$difference])),
    ]);

    expect($create)->toThrow(ValidationException::class, 'This idempotency key was already used for a different notification.');
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertNothingPushed();
})->with([
    'other content' => [['content' => 'Your order is delayed.']],
    'other recipient' => [['recipient' => '+905559876543']],
    'other channel' => [['channel' => 'email', 'recipient' => 'customer@example.com']],
    'other priority' => [['priority' => 'high']],
    'a delivery time' => [['scheduled_at' => '2026-12-01T09:00:00Z']],
]);

test('reports the reused idempotency key at its position in a batch and rolls the batch back', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    Notification::factory()->for($user)->withIdempotencyKey('campaign-42-user-7')->create(['recipient' => '+905551234567', 'content' => 'Original']);

    try {
        app(CreateNotificationsAction::class)->handleBatch($user, [
            NewNotificationData::fromValidated(smsNotificationPayload()),
            NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'Changed', 'idempotency_key' => 'campaign-42-user-7'])),
        ]);
        $this->fail('The reused idempotency key was not refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['notifications.1.idempotency_key' => ['This idempotency key was already used for a different notification.']]);
    }

    $this->assertDatabaseCount('notification_batches', 0);
    $this->assertDatabaseCount('notifications', 1);
});

test('refuses an idempotency key that a concurrent request used first for a different notification', function () {
    Queue::fake([SendNotificationJob::class]);
    $user = User::factory()->create();
    DB::beforeExecuting(function (string $query) use ($user): void {
        static $hasInsertedConcurrently = false;

        if ($hasInsertedConcurrently || ! str_contains($query, 'on conflict do nothing')) {
            return;
        }

        $hasInsertedConcurrently = true;
        Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create(['recipient' => '+905551234567', 'content' => 'From the other request']);
    });

    $create = fn () => app(CreateNotificationsAction::class)->handle($user, [
        NewNotificationData::fromValidated(smsNotificationPayload(['content' => 'From this request', 'idempotency_key' => 'order-1001-shipped'])),
    ]);

    expect($create)->toThrow(ValidationException::class, 'This idempotency key was already used for a different notification.');
    // The simulated concurrent insert shares this request's connection, so the rollback takes it
    // along; what matters is that nothing of this request was kept or queued.
    $this->assertDatabaseMissing('notifications', ['content' => 'From this request']);
    Queue::assertNothingPushed();
});
