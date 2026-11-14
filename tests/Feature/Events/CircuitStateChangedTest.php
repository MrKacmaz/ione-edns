<?php

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Events\CircuitStateChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Queue;

test('broadcasts the channel and its new state to the operators as circuit.changed', function () {
    $event = new CircuitStateChanged(NotificationChannel::Push, CircuitState::HalfOpen);

    expect($event->broadcastOn()[0]->name)->toBe('private-operators');
    expect($event->broadcastAs())->toBe('circuit.changed');
    expect($event->broadcastWith())->toBe(['channel' => 'push', 'state' => 'half_open']);
});

test('sends the broadcast from the broadcasts queue, apart from the delivery queues', function () {
    Queue::fake([BroadcastEvent::class]);

    CircuitStateChanged::dispatch(NotificationChannel::Sms, CircuitState::Open);

    Queue::assertPushedOn('broadcasts', BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->event instanceof CircuitStateChanged);
});

test('tries the broadcast again after a short wait, so a brief Reverb outage does not lose it', function () {
    Queue::fake([BroadcastEvent::class]);

    CircuitStateChanged::dispatch(NotificationChannel::Sms, CircuitState::Open);

    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job): bool => $job->tries === 3 && $job->backoff === [5, 30]);
});
