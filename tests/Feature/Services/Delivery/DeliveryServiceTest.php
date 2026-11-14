<?php

use App\Data\CircuitPermitData;
use App\Data\GatewayResponseData;
use App\Enums\CircuitState;
use App\Enums\DeliveryOutcome;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use App\Notifications\DeliveryCircuitChangedNotification;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Delivery\Contracts\NotificationGateway;
use App\Services\Delivery\DeliveryService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\Support\FakeCircuitBreaker;

beforeEach(function () {
    config([
        'notifications.gateway.url' => 'https://gateway.test/notify',
        'notifications.delivery.max_attempts' => 5,
        'notifications.delivery.backoff.base_seconds' => 30,
    ]);
});

test('marks the notification sent and records the attempt when the provider accepts it', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Sent);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Sent)
        ->provider_message_id->toBe('msg-123')
        ->attempts->toBe(1)
        ->sent_at->not->toBeNull();
    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'outcome' => GatewayOutcome::Accepted->value,
        'http_status' => 202,
        'provider_message_id' => 'msg-123',
    ]);
});

test('puts the notification back in line with a backoff delay after a retryable failure', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::RetryScheduled)
        ->and($result->retryDelaySeconds)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(30);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Queued)
        ->attempts->toBe(1)
        ->last_error->toBe('The provider responded with HTTP 503.');
});

test('records when the next attempt is due, so no other job for the notification can run it sooner', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $this->travelTo('2026-11-14 10:00:00.000000');
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($notification->refresh()->next_attempt_at?->format('Y-m-d H:i:s.u'))
        ->toBe(now()->addSeconds((int) $result->retryDelaySeconds)->format('Y-m-d H:i:s.u'));
});

test('fails the notification when the last attempt fails', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->queued()->create(['attempts' => 4]);

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Failed);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Failed)
        ->attempts->toBe(5)
        ->failed_at->not->toBeNull();
});

test('fails the notification at once when the provider rejects it', function () {
    Http::fake(['gateway.test/*' => Http::response([], 400)]);
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Failed);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Failed)
        ->attempts->toBe(1)
        ->last_error->toBe('The provider responded with HTTP 400.');
});

test('skips a notification that is no longer claimable without calling the provider', function () {
    Http::fake();
    $notification = Notification::factory()->cancelled()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Skipped);
    Http::assertNothingSent();
    $this->assertDatabaseCount('notification_attempts', 0);
});

test('defers a job that comes before the notification is due, without calling the provider or using an attempt', function (array $attributes, int $expectedDelaySeconds) {
    Http::fake();
    Log::spy();
    $this->travelTo('2026-11-14 10:00:00.000000');
    $notification = Notification::factory()->queued()->create(['attempts' => 1, ...$attributes]);

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Deferred)
        ->and($result->retryDelaySeconds)->toBe($expectedDelaySeconds);
    expect($notification->refresh())->status->toBe(NotificationStatus::Queued)->attempts->toBe(1);
    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->once()->with('notification.delivery.deferred', [
        'notification_id' => $notification->id,
        'due_in_seconds' => $expectedDelaySeconds,
    ]);
})->with([
    'a duplicate job during the backoff' => [['next_attempt_at' => '2026-11-14 10:00:20.000000'], 20],
    'a job woken just before its scheduled time' => [['scheduled_at' => '2026-11-14 10:00:00.400000'], 1],
]);

test('delivers a scheduled notification whose time has come even before it was marked as queued', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $notification = Notification::factory()->scheduled()->create(['scheduled_at' => now()->subSecond()]);

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Sent)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

test('logs the outcome of each attempt with its delivery details', function (int $status, string $level, string $event) {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], $status)]);
    Log::spy();
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    Log::shouldHaveReceived($level)->once()->with($event, Mockery::on(fn (array $context): bool => $context['channel'] === 'sms'
        && $context['priority'] === 'normal'
        && $context['attempt'] === 1
        && $context['http_status'] === $status
        && is_int($context['duration_ms'])));
})->with([
    'sent' => [202, 'info', 'notification.delivery.sent'],
    'retry scheduled' => [503, 'warning', 'notification.delivery.retry_scheduled'],
    'failed' => [400, 'error', 'notification.delivery.failed'],
]);

test('treats an unexpected gateway error as retryable instead of leaving the notification processing', function () {
    $this->app->instance(NotificationGateway::class, new class implements NotificationGateway
    {
        public function send(Notification $notification): GatewayResponseData
        {
            throw new RuntimeException('Gateway exploded');
        }
    });
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::RetryScheduled);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Queued)
        ->last_error->toBe('The delivery attempt failed with an internal error.');
});

test('feeds the provider\'s answer to the channel\'s circuit breaker', function (int $status, GatewayOutcome $outcome) {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], $status)]);
    $circuitBreaker = new FakeCircuitBreaker;
    $this->app->instance(CircuitBreaker::class, $circuitBreaker);
    $notification = Notification::factory()->email()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    expect($circuitBreaker->recorded)->toBe([['channel' => NotificationChannel::Email, 'outcome' => $outcome]]);
})->with([
    'accepted' => [202, GatewayOutcome::Accepted],
    'retryable failure' => [503, GatewayOutcome::RetryableFailure],
    'permanent failure' => [400, GatewayOutcome::PermanentFailure],
]);

test('leaves the circuit breaker alone when the notification is skipped', function () {
    $circuitBreaker = new FakeCircuitBreaker;
    $this->app->instance(CircuitBreaker::class, $circuitBreaker);
    $notification = Notification::factory()->cancelled()->create();

    app(DeliveryService::class)->deliver($notification->id);

    expect($circuitBreaker->recorded)->toBe([]);
});

test('alerts the operators when an attempt opens the channel\'s circuit', function () {
    NotificationFacade::fake();
    config(['notifications.alerts.mail_to' => ['ops@example.com']]);
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(transition: CircuitState::Open));
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    NotificationFacade::assertSentOnDemand(
        DeliveryCircuitChangedNotification::class,
        fn (DeliveryCircuitChangedNotification $alert, array $channels, AnonymousNotifiable $notifiable): bool => $alert->channel === NotificationChannel::Sms
            && $alert->state === CircuitState::Open
            && $notifiable->routes['mail'] === ['ops@example.com'],
    );
});

test('keeps the delivery result when the circuit breaker cannot record it', function () {
    Exceptions::fake();
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $this->app->instance(CircuitBreaker::class, new class implements CircuitBreaker
    {
        public function permit(NotificationChannel $channel): CircuitPermitData
        {
            return CircuitPermitData::granted();
        }

        public function record(NotificationChannel $channel, GatewayOutcome $outcome): ?CircuitState
        {
            throw new RuntimeException('Redis went away');
        }

        public function state(NotificationChannel $channel): CircuitState
        {
            return CircuitState::Closed;
        }
    });
    $notification = Notification::factory()->queued()->create();

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::Sent)
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Sent);
    Exceptions::assertReported(RuntimeException::class);
});

test('announces to the owner when the notification reaches a final status', function (int $status, string $finalStatus) {
    Event::fake([NotificationStatusChanged::class]);
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], $status)]);
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
        && $event->notification['status'] === $finalStatus
        && $event->userId === $notification->user_id);
})->with([
    'sent' => [202, 'sent'],
    'failed' => [400, 'failed'],
]);

test('announces nothing while the notification waits for another attempt', function () {
    Event::fake([NotificationStatusChanged::class]);
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    Event::assertNotDispatched(NotificationStatusChanged::class);
});

test('puts the notification back in line when the fourth of five attempts fails', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->queued()->create(['attempts' => 3]);

    $result = app(DeliveryService::class)->deliver($notification->id);

    expect($result->outcome)->toBe(DeliveryOutcome::RetryScheduled);
    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Queued)
        ->attempts->toBe(4);
});

test('records a failed attempt with its status and error in the delivery history', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'outcome' => GatewayOutcome::RetryableFailure->value,
        'http_status' => 503,
        'provider_message_id' => null,
        'error' => 'The provider responded with HTTP 503.',
    ]);
});

test('reports an unexpected gateway error', function () {
    Exceptions::fake();
    $this->app->instance(NotificationGateway::class, new class implements NotificationGateway
    {
        public function send(Notification $notification): GatewayResponseData
        {
            throw new RuntimeException('Gateway exploded');
        }
    });
    $notification = Notification::factory()->queued()->create();

    app(DeliveryService::class)->deliver($notification->id);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Gateway exploded');
});

test('logs that it skipped a notification that is no longer claimable', function () {
    Log::spy();
    $notification = Notification::factory()->cancelled()->create();

    app(DeliveryService::class)->deliver($notification->id);

    Log::shouldHaveReceived('info')->once()->with('notification.delivery.skipped', ['notification_id' => $notification->id]);
});

test('scopes the log context of the attempt to the notification and its correlation id', function () {
    $gateway = new class implements NotificationGateway
    {
        /**
         * @var array<string, mixed>
         */
        public array $contextDuringSend = [];

        public function send(Notification $notification): GatewayResponseData
        {
            $this->contextDuringSend = Context::only(['notification_id', 'correlation_id']);

            return GatewayResponseData::accepted(202, 'msg-123');
        }
    };
    $this->app->instance(NotificationGateway::class, $gateway);
    $notification = Notification::factory()->queued()->create(['correlation_id' => '0f8fad5b-d9cb-469f-a165-70867728950e']);

    app(DeliveryService::class)->deliver($notification->id);

    expect($gateway->contextDuringSend)->toEqual([
        'notification_id' => $notification->id,
        'correlation_id' => '0f8fad5b-d9cb-469f-a165-70867728950e',
    ]);
    expect(Context::has('notification_id'))->toBeFalse();
});

test('keeps no attempt when the status change after the provider\'s answer fails, so the two never disagree', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-123'], 202)]);
    $notification = Notification::factory()->queued()->create();
    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'update "notifications" set "status"') && str_contains($query, '"sent_at"')) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(fn () => app(DeliveryService::class)->deliver($notification->id))->toThrow(RuntimeException::class, 'The database went away.');

    $this->assertDatabaseCount('notification_attempts', 0);
    expect($notification->refresh()->status)->toBe(NotificationStatus::Processing);
});
