<?php

use App\Enums\NotificationChannel;
use App\Services\Delivery\RateLimiting\RedisGcraRateLimiter;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    foreach (NotificationChannel::cases() as $channel) {
        Redis::connection()->del(RedisGcraRateLimiter::key($channel));
    }
});

test('spaces reservations of a channel one emission interval apart', function () {
    $rateLimiter = new RedisGcraRateLimiter(app('redis'), perSecond: ['sms' => 10, 'email' => 10, 'push' => 10], maxWaitMilliseconds: 1000);

    $reservations = array_map(fn () => $rateLimiter->reserve(NotificationChannel::Sms), range(1, 3));

    expect(array_column($reservations, 'granted'))->toBe([true, true, true])
        ->and($reservations[0]->waitMilliseconds)->toBe(0)
        ->and($reservations[1]->waitMilliseconds)->toBeGreaterThan(80)->toBeLessThanOrEqual(100)
        ->and($reservations[2]->waitMilliseconds)->toBeGreaterThan(180)->toBeLessThanOrEqual(200);
});

test('spaces sends so that no second ever holds more than the limit, also when the limit does not divide a second evenly', function (int $perSecond, int $expectedInterval) {
    $interval = RedisGcraRateLimiter::emissionIntervalMicroseconds($perSecond);

    expect($interval)->toBe($expectedInterval)
        ->and(intdiv(1_000_000 - 1, $interval) + 1)->toBeLessThanOrEqual($perSecond);
})->with([
    '100 per second' => [100, 10_000],
    '3 per second' => [3, 333_334],
    '7 per second' => [7, 142_858],
]);

test('denies a slot that opens later than the caller accepts, without taking it', function () {
    $rateLimiter = new RedisGcraRateLimiter(app('redis'), perSecond: ['sms' => 1, 'email' => 1, 'push' => 1], maxWaitMilliseconds: 500);

    $first = $rateLimiter->reserve(NotificationChannel::Sms);
    $second = $rateLimiter->reserve(NotificationChannel::Sms);
    $third = $rateLimiter->reserve(NotificationChannel::Sms);

    expect($first->granted)->toBeTrue()
        ->and($second->granted)->toBeFalse()
        ->and($second->waitMilliseconds)->toBeGreaterThan(900)->toBeLessThanOrEqual(1000)
        ->and($third->granted)->toBeFalse()
        ->and($third->waitMilliseconds)->toBeLessThanOrEqual($second->waitMilliseconds);
});

test('keeps a separate schedule per channel', function () {
    $rateLimiter = new RedisGcraRateLimiter(app('redis'), perSecond: ['sms' => 1, 'email' => 1, 'push' => 1], maxWaitMilliseconds: 0);
    $rateLimiter->reserve(NotificationChannel::Sms);

    $email = $rateLimiter->reserve(NotificationChannel::Email);

    expect($email->granted)->toBeTrue()
        ->and($email->waitMilliseconds)->toBe(0);
});

test('schedules the send after the limit at least a full second after the first send', function () {
    $rateLimiter = new RedisGcraRateLimiter(app('redis'), perSecond: ['sms' => 100, 'email' => 100, 'push' => 100], maxWaitMilliseconds: 5000);
    $startedAt = hrtime(true);
    $sendTimes = [];

    foreach (range(1, 101) as $ignored) {
        $requestedAt = (hrtime(true) - $startedAt) / 1_000_000;
        $sendTimes[] = $requestedAt + $rateLimiter->reserve(NotificationChannel::Push)->waitMilliseconds;
    }

    // A few milliseconds of tolerance cover the Redis round trip of each reservation.
    expect($sendTimes[100] - $sendTimes[0])->toBeGreaterThanOrEqual(995);
});

test('refuses to work with a Redis client other than phpredis', function () {
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andReturn(Mockery::mock(Connection::class));
    $rateLimiter = new RedisGcraRateLimiter($redis, perSecond: ['sms' => 10, 'email' => 10, 'push' => 10], maxWaitMilliseconds: 1000);

    $rateLimiter->reserve(NotificationChannel::Sms);
})->throws(UnexpectedValueException::class, 'The rate limiter requires the phpredis Redis client.');

test('throws when Redis answers a reservation with an unexpected reply', function () {
    $connection = new class extends PhpRedisConnection
    {
        public function __construct() {}

        public function eval($script, $numberOfKeys, ...$arguments): bool
        {
            return false;
        }
    };
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andReturn($connection);
    $rateLimiter = new RedisGcraRateLimiter($redis, perSecond: ['sms' => 10, 'email' => 10, 'push' => 10], maxWaitMilliseconds: 1000);

    $rateLimiter->reserve(NotificationChannel::Sms);
})->throws(UnexpectedValueException::class, 'The rate limiter returned an unexpected reply.');

test('limits each channel by its own rate', function () {
    $rateLimiter = new RedisGcraRateLimiter(app('redis'), perSecond: ['sms' => 10, 'email' => 2, 'push' => 100], maxWaitMilliseconds: 1000);

    $smsReservations = [$rateLimiter->reserve(NotificationChannel::Sms), $rateLimiter->reserve(NotificationChannel::Sms)];
    $emailReservations = [$rateLimiter->reserve(NotificationChannel::Email), $rateLimiter->reserve(NotificationChannel::Email)];

    // Each wait shrinks by the time its reservation took to reach Redis, so the lower
    // bounds only prove that each channel spaces its sends by its own interval.
    expect($rateLimiter->limitPerSecond(NotificationChannel::Email))->toBe(2)
        ->and($smsReservations[1]->waitMilliseconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(100)
        ->and($emailReservations[1]->waitMilliseconds)->toBeGreaterThan(100)->toBeLessThanOrEqual(500);
});

test('grants nothing on a paused channel and asks the job to look again in a few seconds, without calling Redis', function () {
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldNotReceive('connection');
    $rateLimiter = new RedisGcraRateLimiter($redis, perSecond: ['sms' => 0, 'email' => 100, 'push' => 100], maxWaitMilliseconds: 1000);

    $reservation = $rateLimiter->reserve(NotificationChannel::Sms);

    expect($reservation->granted)->toBeFalse()
        ->and($reservation->waitMilliseconds)->toBe(5000)
        ->and($rateLimiter->limitPerSecond(NotificationChannel::Sms))->toBe(0);
});
