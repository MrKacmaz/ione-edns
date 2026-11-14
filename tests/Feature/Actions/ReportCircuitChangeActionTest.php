<?php

use App\Actions\ReportCircuitChangeAction;
use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Events\CircuitStateChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

test('logs the change with the channel', function (CircuitState $state, string $level, string $event) {
    Log::spy();

    app(ReportCircuitChangeAction::class)->handle(NotificationChannel::Push, $state);

    Log::shouldHaveReceived($level)->once()->with($event, ['channel' => 'push']);
})->with([
    'opened' => [CircuitState::Open, 'warning', 'notification.circuit.opened'],
    'closed' => [CircuitState::Closed, 'info', 'notification.circuit.closed'],
]);

test('tells open dashboards about every change', function () {
    Event::fake([CircuitStateChanged::class]);
    $action = app(ReportCircuitChangeAction::class);

    $action->handle(NotificationChannel::Email, CircuitState::Open);
    $action->handle(NotificationChannel::Email, CircuitState::Open);

    Event::assertDispatchedTimes(CircuitStateChanged::class, 2);
    Event::assertDispatched(CircuitStateChanged::class, fn (CircuitStateChanged $event): bool => $event->channel === NotificationChannel::Email
        && $event->state === CircuitState::Open);
});
