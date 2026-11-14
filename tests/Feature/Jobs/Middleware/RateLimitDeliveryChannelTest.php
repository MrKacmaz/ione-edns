<?php

use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;
use App\Jobs\Middleware\RateLimitDeliveryChannel;
use App\Jobs\SendNotificationJob;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;
use Tests\Support\FakeChannelRateLimiter;

test('waits for the reserved slot of the job\'s channel and then runs the job', function () {
    Sleep::fake();
    $rateLimiter = new FakeChannelRateLimiter(RateLimitReservationData::granted(25));
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Email))->withFakeQueueInteractions();
    $ran = false;

    (new RateLimitDeliveryChannel($rateLimiter))->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue()
        ->and($rateLimiter->reservedChannels)->toBe([NotificationChannel::Email]);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 25, times: 1);
    $job->assertNotReleased();
});

test('runs the job at once when the slot is free', function () {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $ran = false;

    (new RateLimitDeliveryChannel(new FakeChannelRateLimiter(RateLimitReservationData::granted(0))))
        ->handle($job, function () use (&$ran): void {
            $ran = true;
        });

    expect($ran)->toBeTrue();
    Sleep::assertNeverSlept();
});

test('hands the job back to the queue until a slot opens when none is free soon enough', function () {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $ran = false;

    (new RateLimitDeliveryChannel(new FakeChannelRateLimiter(RateLimitReservationData::denied(1500))))
        ->handle($job, function () use (&$ran): void {
            $ran = true;
        });

    expect($ran)->toBeFalse();
    $job->assertReleased(delay: 2);
    Sleep::assertNeverSlept();
});
