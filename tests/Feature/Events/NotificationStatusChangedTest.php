<?php

use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Queue;

test('broadcasts on the owner\'s private channel under a stable name', function () {
    $notification = Notification::factory()->sent()->create();

    $event = new NotificationStatusChanged($notification);

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$notification->user_id)
        ->and($event->broadcastAs())->toBe('notification.status-changed')
        ->and($event->queue)->toBe('broadcasts');
});

test('carries the delivery outcome but not the content, to keep messages small', function () {
    $notification = Notification::factory()->failed()->create(['content' => 'Your one-time code is 123456.']);

    $payload = (new NotificationStatusChanged($notification))->broadcastWith()['notification'];

    expect($payload)
        ->id->toBe($notification->id)
        ->status->toBe('failed')
        ->attempts->toBe(5)
        ->last_error->toBe('Provider responded with HTTP 503.')
        ->and($payload)->not->toHaveKey('content');
});

test('tries the broadcast again after a short wait, so a brief Reverb outage does not lose it', function () {
    Queue::fake([BroadcastEvent::class]);
    $notification = Notification::factory()->sent()->create();

    NotificationStatusChanged::dispatch($notification);

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->tries === 3 && $job->backoff === [5, 30]);
});
