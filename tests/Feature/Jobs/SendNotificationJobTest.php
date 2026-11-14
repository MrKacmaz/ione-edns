<?php

use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\RetryBackoff;
use Illuminate\Support\Facades\Http;
use Random\Randomizer;
use Tests\Support\FakeChannelRateLimiter;
use Tests\Support\ZeroRandomEngine;

beforeEach(function () {
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
});

test('releases itself for the backoff delay when the attempt should be retried', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503, ['Retry-After' => '40'])]);
    $this->app->instance(RetryBackoff::class, new RetryBackoff(30, 300, 3600, new Randomizer(new ZeroRandomEngine)));
    $notification = Notification::factory()->queued()->create();
    $job = (new SendNotificationJob($notification->id, $notification->channel))->withFakeQueueInteractions();

    $job->handle(app(DeliveryService::class));

    $job->assertReleased(delay: 40);
});

test('finishes without releasing once the notification is sent', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-1'], 202)]);
    $notification = Notification::factory()->queued()->create();
    $job = (new SendNotificationJob($notification->id, $notification->channel))->withFakeQueueInteractions();

    $job->handle(app(DeliveryService::class));

    $job->assertNotReleased();
    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

test('fails the notification when the job gives up while it is being processed', function () {
    $notification = Notification::factory()->processing()->create();

    (new SendNotificationJob($notification->id, $notification->channel))->failed(new RuntimeException('Job timed out'));

    expect($notification->refresh())
        ->status->toBe(NotificationStatus::Failed)
        ->last_error->toBe('The delivery job failed: Job timed out');
});

test('finishes without releasing when the notification is not to be tried again', function (Closure $state, int $status) {
    Http::fake(['gateway.test/*' => Http::response([], $status)]);
    /** @var Notification $notification */
    $notification = $state(Notification::factory())->create();
    $job = (new SendNotificationJob($notification->id, $notification->channel))->withFakeQueueInteractions();

    $job->handle(app(DeliveryService::class));

    $job->assertNotReleased();
    $job->assertNotFailed();
})->with([
    'rejected by the provider' => [fn ($factory) => $factory->queued(), 400],
    'cancelled before the job ran' => [fn ($factory) => $factory->cancelled(), 202],
]);

test('keeps retrying for one day', function () {
    $this->travelTo('2026-11-14T12:00:00Z');

    $retryUntil = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->retryUntil();

    expect($retryUntil->format('Y-m-d H:i:s'))->toBe('2026-11-15 12:00:00');
});

test('takes a send slot of the notification\'s channel before delivering it', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-1'], 202)]);
    $rateLimiter = new FakeChannelRateLimiter(RateLimitReservationData::granted(0));
    $this->app->instance(ChannelRateLimiter::class, $rateLimiter);
    $notification = Notification::factory()->email()->queued()->create();

    SendNotificationJob::dispatchSync($notification->id, $notification->channel);

    expect($rateLimiter->reservedChannels)->toBe([NotificationChannel::Email]);
    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

test('waits longer after each unexpected exception, so a short outage does not fail the notification at once', function () {
    $job = new SendNotificationJob('notification-id', NotificationChannel::Sms);

    expect($job->backoff())->toBe([10, 30, 60, 120])
        ->and($job->maxExceptions)->toBe(5);
});
