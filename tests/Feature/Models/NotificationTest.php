<?php

use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use Database\Factories\NotificationFactory;
use Illuminate\Support\Facades\Event;

describe('claimForDelivery', function () {
    test('claims a waiting or queued notification and counts the attempt', function (Closure $state) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create(['attempts' => 2]);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)
            ->id->toBe($notification->id)
            ->status->toBe(NotificationStatus::Processing)
            ->attempts->toBe(3);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'processing', 'attempts' => 3]);
    })->with([
        'waiting' => [fn (NotificationFactory $factory) => $factory],
        'queued' => [fn (NotificationFactory $factory) => $factory->queued()],
    ]);

    test('claims a scheduled notification once its delivery time has come', function (int $secondsFromNow) {
        $this->freezeSecond();
        $notification = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds($secondsFromNow)]);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)
            ->id->toBe($notification->id)
            ->status->toBe(NotificationStatus::Processing)
            ->attempts->toBe(1);
    })->with([
        'due this very second' => [0],
        'overdue' => [-300],
    ]);

    test('does not claim a scheduled notification before its delivery time', function () {
        $this->freezeSecond();
        $notification = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSecond()]);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)->toBeNull();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'scheduled', 'attempts' => 0]);
    });

    test('does not claim a queued notification before it is due, so an early job neither skips the backoff nor sends ahead of the schedule', function (array $attributes) {
        $this->travelTo('2026-11-14 10:00:00.000000');
        $notification = Notification::factory()->queued()->create(['attempts' => 1, ...$attributes]);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)->toBeNull();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'queued', 'attempts' => 1]);
    })->with([
        'waiting for its next attempt' => [['next_attempt_at' => '2026-11-14 10:00:30.000000']],
        'scheduled 250 ms ahead' => [['scheduled_at' => '2026-11-14 10:00:00.250000']],
    ]);

    test('claims a queued notification once its next attempt is due', function () {
        $this->travelTo('2026-11-14 10:00:30.000000');
        $notification = Notification::factory()->queued()->create(['attempts' => 1, 'next_attempt_at' => '2026-11-14 10:00:30.000000']);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)->status->toBe(NotificationStatus::Processing)->attempts->toBe(2);
    });

    test('does not claim a notification that is being processed or finished', function (Closure $state, string $status) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create(['attempts' => 2]);

        $claimed = Notification::claimForDelivery($notification->id);

        expect($claimed)->toBeNull();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => $status, 'attempts' => 2]);
    })->with([
        'processing' => [fn (NotificationFactory $factory) => $factory->processing(), 'processing'],
        'sent' => [fn (NotificationFactory $factory) => $factory->sent(), 'sent'],
        'failed' => [fn (NotificationFactory $factory) => $factory->failed(), 'failed'],
        'cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled(), 'cancelled'],
    ]);
});

describe('secondsUntilDue', function () {
    test('tells how long a pending notification still has to wait, rounded up to whole seconds', function (Closure $state, array $attributes, int $expectedSeconds) {
        $this->travelTo('2026-11-14 10:00:00.000000');
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create($attributes);

        expect(Notification::secondsUntilDue($notification->id))->toBe($expectedSeconds);
    })->with([
        'queued, next attempt ahead' => [fn (NotificationFactory $factory) => $factory->queued(), ['next_attempt_at' => '2026-11-14 10:00:44.100000'], 45],
        'queued, scheduled within the second' => [fn (NotificationFactory $factory) => $factory->queued(), ['scheduled_at' => '2026-11-14 10:00:00.250000'], 1],
        'scheduled' => [fn (NotificationFactory $factory) => $factory->scheduled(), ['scheduled_at' => '2026-11-14 10:05:00.000000'], 300],
        'queued and already due, after a claim that read the clock earlier' => [fn (NotificationFactory $factory) => $factory->queued(), ['next_attempt_at' => '2026-11-14 09:59:59.000000'], 1],
    ]);

    test('has nothing to wait for when the notification has no due time or no attempt is pending', function (Closure $state, array $attributes) {
        $this->travelTo('2026-11-14 10:00:00.000000');
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create($attributes);

        expect(Notification::secondsUntilDue($notification->id))->toBeNull();
    })->with([
        'waiting' => [fn (NotificationFactory $factory) => $factory, []],
        'processing' => [fn (NotificationFactory $factory) => $factory->processing(), ['next_attempt_at' => '2026-11-14 10:01:00.000000']],
        'cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled(), ['scheduled_at' => '2026-11-14 10:05:00.000000']],
    ]);

    test('has nothing to wait for when the notification does not exist', function () {
        expect(Notification::secondsUntilDue('0199a4f2-0000-7000-8000-000000000000'))->toBeNull();
    });
});

describe('markSent, markFailed and markRetryPending', function () {
    test('marks a processing notification sent, clears its last error and announces it to the owner', function () {
        $this->travelTo('2026-11-14 10:00:00.250000');
        $notification = Notification::factory()->processing()->create(['last_error' => 'The provider responded with HTTP 503.']);
        Event::fake([NotificationStatusChanged::class]);

        $notification->markSent('msg-123');

        expect($notification)
            ->status->toBe(NotificationStatus::Sent)
            ->provider_message_id->toBe('msg-123')
            ->last_error->toBeNull()
            ->sent_at->toJSON()->toBe('2026-11-14T10:00:00.250000Z');
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'sent', 'provider_message_id' => 'msg-123']);
        Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
            && $event->notification['status'] === 'sent');
    });

    test('marks a processing notification failed with its error and announces it to the owner', function () {
        $this->travelTo('2026-11-14 10:00:00.250000');
        $notification = Notification::factory()->processing()->create();
        Event::fake([NotificationStatusChanged::class]);

        $notification->markFailed('The provider responded with HTTP 400.');

        expect($notification)
            ->status->toBe(NotificationStatus::Failed)
            ->last_error->toBe('The provider responded with HTTP 400.')
            ->failed_at->toJSON()->toBe('2026-11-14T10:00:00.250000Z');
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'failed']);
        Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
            && $event->notification['status'] === 'failed');
    });

    test('puts a processing notification back in line until its next attempt, without announcing it', function () {
        $this->travelTo('2026-11-14 10:00:00.250000');
        $notification = Notification::factory()->processing()->create();
        Event::fake([NotificationStatusChanged::class]);

        $notification->markRetryPending('The provider responded with HTTP 503.', now()->addSeconds(45));

        expect($notification)
            ->status->toBe(NotificationStatus::Queued)
            ->last_error->toBe('The provider responded with HTTP 503.')
            ->next_attempt_at->toJSON()->toBe('2026-11-14T10:00:45.250000Z');
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'queued']);
        Event::assertNotDispatched(NotificationStatusChanged::class);
    });

    test('leaves a notification that is no longer processing untouched and unannounced', function (Closure $state, Closure $transition, string $status) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create();
        Event::fake([NotificationStatusChanged::class]);

        $transition($notification);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => $status]);
        Event::assertNotDispatched(NotificationStatusChanged::class);
    })->with([
        'marking a failed notification sent' => [
            fn (NotificationFactory $factory) => $factory->failed(),
            fn (Notification $notification) => $notification->markSent('msg-123'),
            'failed',
        ],
        'marking a sent notification failed' => [
            fn (NotificationFactory $factory) => $factory->sent(),
            fn (Notification $notification) => $notification->markFailed('The provider responded with HTTP 400.'),
            'sent',
        ],
        'putting a failed notification back in line' => [
            fn (NotificationFactory $factory) => $factory->failed(),
            fn (Notification $notification) => $notification->markRetryPending('The provider responded with HTTP 503.', now()),
            'failed',
        ],
    ]);
});

describe('markFailedIfUndelivered', function () {
    test('fails a notification whose job gave up before delivering it and announces it to the owner', function (Closure $state) {
        $this->travelTo('2026-11-14 10:00:00.250000');
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create();
        Event::fake([NotificationStatusChanged::class]);

        Notification::markFailedIfUndelivered($notification->id, 'The delivery job failed: Job timed out');

        expect($notification->refresh())
            ->status->toBe(NotificationStatus::Failed)
            ->last_error->toBe('The delivery job failed: Job timed out')
            ->failed_at->toJSON()->toBe('2026-11-14T10:00:00.250000Z');
        Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
            && $event->notification['status'] === 'failed'
            && $event->notification['last_error'] === 'The delivery job failed: Job timed out');
    })->with([
        'being processed when its job died' => [fn (NotificationFactory $factory) => $factory->processing()],
        'still queued when its job ran out of time' => [fn (NotificationFactory $factory) => $factory->queued()],
    ]);

    test('leaves a notification that no job is delivering alone', function (Closure $state, string $status) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create();
        Event::fake([NotificationStatusChanged::class]);

        Notification::markFailedIfUndelivered($notification->id, 'The delivery job failed: Job timed out');

        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => $status, 'failed_at' => null]);
        Event::assertNotDispatched(NotificationStatusChanged::class);
    })->with([
        'waiting, which the stale-notification sweep recovers' => [fn (NotificationFactory $factory) => $factory, 'waiting'],
        'sent' => [fn (NotificationFactory $factory) => $factory->sent(), 'sent'],
        'cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled(), 'cancelled'],
    ]);
});

describe('resetForRedelivery', function () {
    test('gives a failed notification a fresh set of attempts and reports the reset', function () {
        $notification = Notification::factory()->failed()->create(['next_attempt_at' => now()->subMinute()]);

        $wasReset = $notification->resetForRedelivery();

        expect($wasReset)->toBeTrue();
        expect($notification)
            ->status->toBe(NotificationStatus::Waiting)
            ->attempts->toBe(0)
            ->last_error->toBeNull()
            ->failed_at->toBeNull()
            ->next_attempt_at->toBeNull();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'waiting', 'attempts' => 0, 'last_error' => null, 'failed_at' => null]);
    });

    test('refuses to reset a notification that has not failed', function (Closure $state, string $status) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create(['attempts' => 2]);

        $wasReset = $notification->resetForRedelivery();

        expect($wasReset)->toBeFalse();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => $status, 'attempts' => 2]);
    })->with([
        'waiting' => [fn (NotificationFactory $factory) => $factory, 'waiting'],
        'scheduled' => [fn (NotificationFactory $factory) => $factory->scheduled(), 'scheduled'],
        'queued' => [fn (NotificationFactory $factory) => $factory->queued(), 'queued'],
        'processing' => [fn (NotificationFactory $factory) => $factory->processing(), 'processing'],
        'sent' => [fn (NotificationFactory $factory) => $factory->sent(), 'sent'],
        'cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled(), 'cancelled'],
    ]);
});

describe('cancel', function () {
    test('cancels a notification that no worker has picked up yet and announces it to the owner', function (Closure $state) {
        $this->travelTo('2026-11-14 10:00:00.250000');
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create();
        Event::fake([NotificationStatusChanged::class]);

        $wasCancelled = $notification->cancel();

        expect($wasCancelled)->toBeTrue();
        expect($notification)
            ->status->toBe(NotificationStatus::Cancelled)
            ->cancelled_at->toJSON()->toBe('2026-11-14T10:00:00.250000Z');
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => 'cancelled']);
        Event::assertDispatched(NotificationStatusChanged::class, fn (NotificationStatusChanged $event): bool => $event->notification['id'] === $notification->id
            && $event->notification['status'] === 'cancelled');
    })->with([
        'waiting' => [fn (NotificationFactory $factory) => $factory],
        'scheduled' => [fn (NotificationFactory $factory) => $factory->scheduled()],
        'queued' => [fn (NotificationFactory $factory) => $factory->queued()],
    ]);

    test('refuses to cancel a notification that is processing or finished, without announcing it', function (Closure $state, string $status) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory())->create();
        Event::fake([NotificationStatusChanged::class]);

        $wasCancelled = $notification->cancel();

        expect($wasCancelled)->toBeFalse();
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'status' => $status]);
        Event::assertNotDispatched(NotificationStatusChanged::class);
    })->with([
        'processing' => [fn (NotificationFactory $factory) => $factory->processing(), 'processing'],
        'sent' => [fn (NotificationFactory $factory) => $factory->sent(), 'sent'],
        'failed' => [fn (NotificationFactory $factory) => $factory->failed(), 'failed'],
        'already cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled(), 'cancelled'],
    ]);

    test('shows the current status after a worker claimed the notification since it was loaded', function () {
        $notification = Notification::factory()->queued()->create();
        Notification::claimForDelivery($notification->id);

        $wasCancelled = $notification->cancel();

        expect($wasCancelled)->toBeFalse();
        expect($notification->status)->toBe(NotificationStatus::Processing);
    });
});

describe('dueForDispatch', function () {
    test('selects scheduled notifications that come due within the next minute', function () {
        $this->freezeSecond();
        $overdue = Notification::factory()->scheduled()->create(['scheduled_at' => now()->subMinutes(5)]);
        $dueAtTheEndOfTheMinute = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(60)]);
        Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(61)]);
        Notification::factory()->queued()->create(['scheduled_at' => now()->subMinute()]);
        Notification::factory()->cancelled()->create(['scheduled_at' => now()->subMinute()]);
        Notification::factory()->create();

        $dueIds = Notification::query()->dueForDispatch()->pluck('id')->all();

        expect($dueIds)->toEqualCanonicalizing([$overdue->id, $dueAtTheEndOfTheMinute->id]);
    });
});

describe('isDueForDispatch', function () {
    test('treats a waiting notification as due', function () {
        $notification = Notification::factory()->withoutParents()->make();

        expect($notification->isDueForDispatch())->toBeTrue();
    });

    test('treats a scheduled notification as due once its delivery time is at most a minute away', function (int $secondsFromNow, bool $isDue) {
        $this->freezeSecond();
        $notification = Notification::factory()->withoutParents()->scheduled()->make(['scheduled_at' => now()->addSeconds($secondsFromNow)]);

        expect($notification->isDueForDispatch())->toBe($isDue);
    })->with([
        'overdue' => [-300, true],
        'exactly a minute away' => [60, true],
        'just over a minute away' => [61, false],
        'an hour away' => [3600, false],
    ]);

    test('does not treat a notification that already left the outbox as due', function (Closure $state) {
        /** @var Notification $notification */
        $notification = $state(Notification::factory()->withoutParents())->make();

        expect($notification->isDueForDispatch())->toBeFalse();
    })->with([
        'queued' => [fn (NotificationFactory $factory) => $factory->queued()],
        'processing' => [fn (NotificationFactory $factory) => $factory->processing()],
        'sent' => [fn (NotificationFactory $factory) => $factory->sent()],
        'failed' => [fn (NotificationFactory $factory) => $factory->failed()],
        'cancelled' => [fn (NotificationFactory $factory) => $factory->cancelled()],
    ]);
});
