<?php

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Notifications\DeliveryCircuitChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'notifications.circuit_breaker.failure_rate_percent' => 50,
        'notifications.circuit_breaker.minimum_attempts' => 20,
        'notifications.circuit_breaker.window_seconds' => 60,
        'notifications.circuit_breaker.cooldown_seconds' => 30,
    ]);
});

test('explains why a channel\'s deliveries paused and when they are probed again', function () {
    $mail = (new DeliveryCircuitChangedNotification(NotificationChannel::Sms, CircuitState::Open))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('The sms delivery circuit opened')
        ->and($mail->level)->toBe('error')
        ->and($mail->introLines)->toBe([
            'At least 50% of at least 20 calls to the sms provider failed within 60 seconds, so sms deliveries are paused.',
            'Waiting notifications keep their delivery attempts. A probe call is made in 30 seconds, and deliveries resume once a probe succeeds.',
        ]);
});

test('tells that a channel\'s deliveries resumed', function () {
    $mail = (new DeliveryCircuitChangedNotification(NotificationChannel::Email, CircuitState::Closed))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe('The email delivery circuit closed')
        ->and($mail->level)->toBe('success')
        ->and($mail->introLines)->toBe(['A probe call to the email provider succeeded, so email deliveries have resumed.']);
});

test('goes through the queue so a slow mail server does not hold up the delivery worker', function () {
    Queue::fake([SendQueuedNotifications::class]);

    (new AnonymousNotifiable)
        ->route('mail', 'ops@example.com')
        ->notify(new DeliveryCircuitChangedNotification(NotificationChannel::Sms, CircuitState::Open));

    Queue::assertPushed(SendQueuedNotifications::class);
});

test('tries the mail again after a wait, so a brief mail server outage does not lose the alert', function () {
    Queue::fake([SendQueuedNotifications::class]);

    (new AnonymousNotifiable)
        ->route('mail', 'ops@example.com')
        ->notify(new DeliveryCircuitChangedNotification(NotificationChannel::Sms, CircuitState::Open));

    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->tries === 3 && $job->backoff() === [10, 60]);
});
