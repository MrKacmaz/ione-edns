<?php

use App\Enums\GatewayOutcome;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

test('queues a failed notification again with a fresh set of attempts and keeps its history', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->failed()->create();
    $notification->deliveryAttempts()->create([
        'attempt' => 5,
        'outcome' => GatewayOutcome::RetryableFailure,
        'http_status' => 503,
        'error' => 'The provider responded with HTTP 503.',
        'duration_ms' => 120,
    ]);
    Sanctum::actingAs($notification->user);

    $response = $this->postJson(route('api.v1.notifications.retry', $notification));

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.attempts', 0)
        ->assertJsonPath('data.last_error', null)
        ->assertJsonPath('data.failed_at', null);
    Queue::assertPushedOn('notifications-normal', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $notification->id);
    $this->assertDatabaseCount('notification_attempts', 1);
});

test('returns 409 for a notification that has not failed', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->sent()->create();
    Sanctum::actingAs($notification->user);

    $response = $this->postJson(route('api.v1.notifications.retry', $notification));

    $response->assertConflict()
        ->assertJsonPath('message', 'Only failed notifications can be retried; this notification is sent.');
    Queue::assertNotPushed(SendNotificationJob::class);
});

test('returns 404 and does not retry another user\'s notification', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->failed()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notifications.retry', $notification))->assertNotFound();

    Queue::assertNotPushed(SendNotificationJob::class);
    expect($notification->refresh()->status->value)->toBe('failed');
});

test('returns 401 when no token is provided', function () {
    $notification = Notification::factory()->failed()->create();

    $this->postJson(route('api.v1.notifications.retry', $notification))->assertUnauthorized();
});

test('stores the retried notification as queued with its attempts reset', function () {
    Queue::fake([SendNotificationJob::class]);
    $notification = Notification::factory()->failed()->create();
    Sanctum::actingAs($notification->user);

    $this->postJson(route('api.v1.notifications.retry', $notification))->assertAccepted();

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => 'queued',
        'attempts' => 0,
        'last_error' => null,
        'failed_at' => null,
    ]);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('returns 404 for an id that is not a uuid', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notifications.retry', 'not-a-uuid'))->assertNotFound();
});
