<?php

use App\Actions\ReportCircuitChangeAction;
use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Events\CircuitStateChanged;
use App\Notifications\DeliveryCircuitChangedNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'notifications.alerts.mail_to' => ['ops@example.com', 'oncall@example.com'],
        'notifications.alerts.throttle_minutes' => 10,
    ]);
});

test('logs the change with the channel', function (CircuitState $state, string $level, string $event) {
    Notification::fake();
    Log::spy();

    app(ReportCircuitChangeAction::class)->handle(NotificationChannel::Push, $state);

    Log::shouldHaveReceived($level)->once()->with($event, ['channel' => 'push']);
})->with([
    'opened' => [CircuitState::Open, 'warning', 'notification.circuit.opened'],
    'closed' => [CircuitState::Closed, 'info', 'notification.circuit.closed'],
]);

test('mails every operator address', function () {
    Notification::fake();

    app(ReportCircuitChangeAction::class)->handle(NotificationChannel::Sms, CircuitState::Open);

    Notification::assertSentOnDemand(
        DeliveryCircuitChangedNotification::class,
        fn (DeliveryCircuitChangedNotification $alert, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === ['ops@example.com', 'oncall@example.com'],
    );
});

test('still mails the operators when telling the dashboards fails', function () {
    Notification::fake();
    Exceptions::fake();
    Event::listen(CircuitStateChanged::class, fn () => throw new RuntimeException('Reverb is down'));

    app(ReportCircuitChangeAction::class)->handle(NotificationChannel::Sms, CircuitState::Open);

    Notification::assertSentOnDemand(DeliveryCircuitChangedNotification::class);
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Reverb is down');
});

test('mails the same change of a channel at most once per throttle window', function () {
    Notification::fake();
    $action = app(ReportCircuitChangeAction::class);

    $action->handle(NotificationChannel::Sms, CircuitState::Open);
    $action->handle(NotificationChannel::Sms, CircuitState::Open);
    $this->travel(11)->minutes();
    $action->handle(NotificationChannel::Sms, CircuitState::Open);

    Notification::assertSentOnDemandTimes(DeliveryCircuitChangedNotification::class, 2);
});

test('throttles each channel and change on its own', function () {
    Notification::fake();
    $action = app(ReportCircuitChangeAction::class);

    $action->handle(NotificationChannel::Sms, CircuitState::Open);
    $action->handle(NotificationChannel::Sms, CircuitState::Closed);
    $action->handle(NotificationChannel::Email, CircuitState::Open);

    Notification::assertSentOnDemandTimes(DeliveryCircuitChangedNotification::class, 3);
});

test('only logs when no operator address is configured', function () {
    Notification::fake();
    config(['notifications.alerts.mail_to' => []]);

    app(ReportCircuitChangeAction::class)->handle(NotificationChannel::Sms, CircuitState::Open);

    Notification::assertNothingSent();
});

test('tells open dashboards at once, also when the mail is throttled', function () {
    Notification::fake();
    Event::fake([CircuitStateChanged::class]);
    $action = app(ReportCircuitChangeAction::class);

    $action->handle(NotificationChannel::Email, CircuitState::Open);
    $action->handle(NotificationChannel::Email, CircuitState::Open);

    Event::assertDispatchedTimes(CircuitStateChanged::class, 2);
    Event::assertDispatched(CircuitStateChanged::class, fn (CircuitStateChanged $event): bool => $event->channel === NotificationChannel::Email
        && $event->state === CircuitState::Open);
    Notification::assertSentOnDemandTimes(DeliveryCircuitChangedNotification::class, 1);
});
