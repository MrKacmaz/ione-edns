<?php

use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

test('cancels only the batch\'s notifications that no worker has picked up yet', function () {
    $batch = NotificationBatch::factory()->create();
    Notification::factory()->inBatch($batch)->create();
    Notification::factory()->inBatch($batch)->queued()->create();
    $sent = Notification::factory()->inBatch($batch)->sent()->create();
    $outsideBatch = Notification::factory()->for($batch->user)->create();
    Sanctum::actingAs($batch->user);

    $response = $this->postJson(route('api.v1.notification-batches.cancel', $batch));

    $response->assertOk()
        ->assertJsonPath('meta.cancelled', 2)
        ->assertJsonPath('data.status_counts.cancelled', 2)
        ->assertJsonPath('data.status_counts.sent', 1)
        ->assertJsonPath('data.is_finished', true);
    expect($sent->refresh()->status->value)->toBe('sent')
        ->and($outsideBatch->refresh()->status->value)->toBe('waiting');
});

test('returns 404 and does not cancel another user\'s batch', function () {
    $batch = NotificationBatch::factory()->create();
    $notification = Notification::factory()->inBatch($batch)->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notification-batches.cancel', $batch))->assertNotFound();

    expect($notification->refresh()->status->value)->toBe('waiting');
});

test('announces each cancelled notification of the batch to the owner', function () {
    Event::fake([NotificationStatusChanged::class]);
    $batch = NotificationBatch::factory()->create();
    $waiting = Notification::factory()->inBatch($batch)->create();
    $queued = Notification::factory()->inBatch($batch)->queued()->create();
    Notification::factory()->inBatch($batch)->sent()->create();
    Sanctum::actingAs($batch->user);

    $this->postJson(route('api.v1.notification-batches.cancel', $batch))->assertOk();

    Event::assertDispatchedTimes(NotificationStatusChanged::class, 2);
    foreach ([$waiting, $queued] as $notification) {
        Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
            && $event->notification['status'] === 'cancelled'
            && $event->notification['cancelled_at'] !== null);
    }
});

test('returns 401 and does not cancel the batch when no token is provided', function () {
    $batch = NotificationBatch::factory()->create();
    $notification = Notification::factory()->inBatch($batch)->create();

    $this->postJson(route('api.v1.notification-batches.cancel', $batch))->assertUnauthorized();

    $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'waiting']);
});

test('returns 404 for an id that is not a uuid', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notification-batches.cancel', 'not-a-uuid'))->assertNotFound();
});
