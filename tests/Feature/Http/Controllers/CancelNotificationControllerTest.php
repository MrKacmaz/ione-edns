<?php

use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

test('cancels a notification that no worker has picked up yet', function () {
    $notification = Notification::factory()->queued()->create();
    Sanctum::actingAs($notification->user);

    $response = $this->postJson(route('api.v1.notifications.cancel', $notification));

    $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($notification->refresh())
        ->status->value->toBe('cancelled')
        ->cancelled_at->not->toBeNull();
});

test('returns 409 and leaves the notification untouched once it is processing or finished', function () {
    $notification = Notification::factory()->processing()->create();
    Sanctum::actingAs($notification->user);

    $response = $this->postJson(route('api.v1.notifications.cancel', $notification));

    $response->assertConflict()->assertJsonPath('message', 'The notification can no longer be cancelled because it is processing.');
    expect($notification->refresh()->status->value)->toBe('processing');
});

test('returns 404 and does not cancel another user\'s notification', function () {
    $notification = Notification::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notifications.cancel', $notification))->assertNotFound();

    expect($notification->refresh()->status->value)->toBe('waiting');
});

test('returns 401 when no token is provided', function () {
    $notification = Notification::factory()->create();

    $this->postJson(route('api.v1.notifications.cancel', $notification))->assertUnauthorized();
});

test('announces the cancellation to the owner, but not a refused one', function () {
    Event::fake([NotificationStatusChanged::class]);
    $cancellable = Notification::factory()->queued()->create();
    $sent = Notification::factory()->for($cancellable->user)->sent()->create();
    Sanctum::actingAs($cancellable->user);

    $this->postJson(route('api.v1.notifications.cancel', $cancellable))->assertOk();
    $this->postJson(route('api.v1.notifications.cancel', $sent))->assertConflict();

    Event::assertDispatchedTimes(NotificationStatusChanged::class, 1);
    Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $cancellable->id
        && $event->notification['status'] === 'cancelled');
});

test('returns 404 for an id that is not a uuid', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notifications.cancel', 'not-a-uuid'))->assertNotFound();
});
