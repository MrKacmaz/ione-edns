<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14 12:00:00');
});

test('gives the notifications that failed since the given time a fresh set of attempts and queues them', function () {
    $recent = Notification::factory()->failed()->create(['attempts' => 5, 'failed_at' => '2026-11-14 11:30:00', 'next_attempt_at' => '2026-11-14 11:29:00']);
    $older = Notification::factory()->failed()->create(['failed_at' => '2026-11-14 10:59:59']);

    $this->artisan('notifications:retry-failed', ['since' => '1 hour ago'])
        ->expectsOutput('Retried 1 failed notifications.')
        ->assertSuccessful();

    expect($recent->refresh())
        ->status->toBe(NotificationStatus::Queued)
        ->attempts->toBe(0)
        ->last_error->toBeNull()
        ->failed_at->toBeNull()
        ->next_attempt_at->toBeNull()
        ->and($older->refresh()->status)->toBe(NotificationStatus::Failed);
    Queue::assertPushedOn('notifications-sms-normal', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $recent->id);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('keeps the delivery history of a retried notification', function () {
    $notification = Notification::factory()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);
    $notification->deliveryAttempts()->create(['attempt' => 1, 'outcome' => 'retryable_failure', 'http_status' => 404, 'error' => 'Gone', 'duration_ms' => 80]);

    $this->artisan('notifications:retry-failed', ['since' => '2026-11-14 11:00'])->assertSuccessful();

    expect($notification->deliveryAttempts()->count())->toBe(1);
});

test('retries only the given channels', function () {
    $sms = Notification::factory()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);
    $email = Notification::factory()->email()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);
    $push = Notification::factory()->push()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);

    $this->artisan('notifications:retry-failed', ['since' => '1 hour ago', '--channel' => ['email', 'push']])->assertSuccessful();

    expect($sms->refresh()->status)->toBe(NotificationStatus::Failed)
        ->and($email->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($push->refresh()->status)->toBe(NotificationStatus::Queued);
});

test('leaves notifications that did not fail alone', function (Closure $state, NotificationStatus $status) {
    /** @var Notification $notification */
    $notification = $state(Notification::factory())->create();

    $this->artisan('notifications:retry-failed', ['since' => '1 hour ago'])
        ->expectsOutput('Retried 0 failed notifications.')
        ->assertSuccessful();

    expect($notification->refresh()->status)->toBe($status);
    Queue::assertNothingPushed();
})->with([
    'sent' => [fn ($factory) => $factory->sent(), NotificationStatus::Sent],
    'cancelled' => [fn ($factory) => $factory->cancelled(), NotificationStatus::Cancelled],
    'queued' => [fn ($factory) => $factory->queued(), NotificationStatus::Queued],
]);

test('only counts the notifications in a dry run', function () {
    $notification = Notification::factory()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);

    $this->artisan('notifications:retry-failed', ['since' => '1 hour ago', '--dry-run' => true])
        ->expectsOutput('1 failed notifications would be retried.')
        ->assertSuccessful();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Failed);
    Queue::assertNothingPushed();
});

test('refuses a time or a channel it cannot read', function (array $arguments, string $error) {
    $notification = Notification::factory()->failed()->create(['failed_at' => '2026-11-14 11:30:00']);

    $this->artisan('notifications:retry-failed', $arguments)
        ->expectsOutput($error)
        ->assertFailed();

    expect($notification->refresh()->status)->toBe(NotificationStatus::Failed);
})->with([
    'unreadable time' => [['since' => 'whenever'], '"whenever" is not a time. Use for example "2 hours ago" or "2026-11-14 09:00".'],
    'unknown channel' => [['since' => '1 hour ago', '--channel' => ['fax']], '"fax" is not a channel. Use one of: sms, email, push.'],
]);
