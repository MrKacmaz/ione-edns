<?php

use App\Data\CircuitPermitData;
use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Jobs\Middleware\CheckDeliveryChannelCircuit;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChannelRateLimiter;
use Tests\Support\FakeCircuitBreaker;

test('runs the job while the circuit lets calls through', function () {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $ran = false;

    (new CheckDeliveryChannelCircuit(new FakeCircuitBreaker))->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
    $job->assertNotReleased();
});

test('hands the job back to the queue until the circuit may let it through', function () {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $ran = false;

    (new CheckDeliveryChannelCircuit(new FakeCircuitBreaker(CircuitPermitData::denied(12_300))))
        ->handle($job, function () use (&$ran): void {
            $ran = true;
        });

    expect($ran)->toBeFalse();
    $job->assertReleased(delay: 13);
});

test('neither takes a rate-limit slot nor uses up an attempt while the circuit is open', function () {
    Http::fake();
    $rateLimiter = new FakeChannelRateLimiter(RateLimitReservationData::granted(0));
    $this->app->instance(ChannelRateLimiter::class, $rateLimiter);
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(CircuitPermitData::denied(5_000)));
    $notification = Notification::factory()->queued()->create();

    SendNotificationJob::dispatchSync($notification->id, $notification->channel);

    expect($rateLimiter->reservedChannels)->toBe([])
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($notification->attempts)->toBe(0);
    Http::assertNothingSent();
});
