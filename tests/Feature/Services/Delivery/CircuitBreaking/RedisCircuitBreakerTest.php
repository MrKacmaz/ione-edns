<?php

use App\Enums\CircuitState;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Services\Delivery\CircuitBreaking\RedisCircuitBreaker;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    foreach (NotificationChannel::cases() as $channel) {
        Redis::connection()->del(RedisCircuitBreaker::stateKey($channel), RedisCircuitBreaker::bucketsKey($channel));
    }

    $this->freezeTime();
});

function circuitBreaker(): RedisCircuitBreaker
{
    return new RedisCircuitBreaker(
        app('redis'),
        windowSeconds: 60,
        minimumAttempts: 4,
        failureRatePercent: 50,
        cooldownSeconds: 30,
        probeTimeoutSeconds: 20,
    );
}

/**
 * Record the given outcomes for the channel and return the transitions they caused.
 *
 * @param  list<GatewayOutcome>  $outcomes
 * @return list<CircuitState|null>
 */
function recordOutcomes(RedisCircuitBreaker $breaker, NotificationChannel $channel, array $outcomes): array
{
    return array_map(fn (GatewayOutcome $outcome): ?CircuitState => $breaker->record($channel, $outcome), $outcomes);
}

/**
 * Open the channel's circuit with four retryable failures.
 */
function openCircuit(RedisCircuitBreaker $breaker, NotificationChannel $channel): void
{
    recordOutcomes($breaker, $channel, array_fill(0, 4, GatewayOutcome::RetryableFailure));
}

test('lets calls through while the circuit is closed', function () {
    $breaker = circuitBreaker();

    expect($breaker->permit(NotificationChannel::Sms)->granted)->toBeTrue()
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Closed);
});

test('opens once the failure rate reaches the threshold over the minimum number of calls', function () {
    $breaker = circuitBreaker();

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, [
        GatewayOutcome::Accepted,
        GatewayOutcome::RetryableFailure,
        GatewayOutcome::Accepted,
        GatewayOutcome::RetryableFailure,
    ]);

    expect($transitions)->toBe([null, null, null, CircuitState::Open])
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Open);
});

test('stays closed below the minimum number of calls, even when every call failed', function () {
    $breaker = circuitBreaker();

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, array_fill(0, 3, GatewayOutcome::RetryableFailure));

    expect($transitions)->toBe([null, null, null])
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Closed);
});

test('counts permanent failures as healthy answers of the provider', function () {
    $breaker = circuitBreaker();

    recordOutcomes($breaker, NotificationChannel::Sms, [
        GatewayOutcome::PermanentFailure,
        GatewayOutcome::PermanentFailure,
        GatewayOutcome::PermanentFailure,
        GatewayOutcome::RetryableFailure,
    ]);

    expect($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Closed);
});

test('forgets calls that fell out of the window', function () {
    $breaker = circuitBreaker();
    recordOutcomes($breaker, NotificationChannel::Sms, array_fill(0, 3, GatewayOutcome::RetryableFailure));
    $this->travel(61)->seconds();

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, [GatewayOutcome::RetryableFailure]);

    expect($transitions)->toBe([null])
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Closed);
});

test('keeps each channel\'s circuit apart', function () {
    $breaker = circuitBreaker();

    openCircuit($breaker, NotificationChannel::Sms);

    expect($breaker->state(NotificationChannel::Email))->toBe(CircuitState::Closed)
        ->and($breaker->permit(NotificationChannel::Email)->granted)->toBeTrue();
});

test('turns calls away until the cooldown ends', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(10)->seconds();

    $permit = $breaker->permit(NotificationChannel::Sms);

    expect($permit->granted)->toBeFalse()
        ->and($permit->waitMilliseconds)->toBe(20_000);
});

test('lets a single probe through after the cooldown and holds everyone else back', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(30)->seconds();

    $probe = $breaker->permit(NotificationChannel::Sms);
    $next = $breaker->permit(NotificationChannel::Sms);

    expect($probe->granted)->toBeTrue()
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::HalfOpen)
        ->and($next->granted)->toBeFalse()
        ->and($next->waitMilliseconds)->toBe(20_000);
});

test('closes when the probe succeeds', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(30)->seconds();
    $breaker->permit(NotificationChannel::Sms);

    $transition = $breaker->record(NotificationChannel::Sms, GatewayOutcome::Accepted);

    expect($transition)->toBe(CircuitState::Closed)
        ->and($breaker->permit(NotificationChannel::Sms)->granted)->toBeTrue();
});

test('starts a fresh window after closing, so earlier failures do not reopen it', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(30)->seconds();
    $breaker->permit(NotificationChannel::Sms);
    $breaker->record(NotificationChannel::Sms, GatewayOutcome::Accepted);

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, [GatewayOutcome::RetryableFailure]);

    expect($transitions)->toBe([null]);
});

test('opens again for another cooldown when the probe fails', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(30)->seconds();
    $breaker->permit(NotificationChannel::Sms);

    $transition = $breaker->record(NotificationChannel::Sms, GatewayOutcome::RetryableFailure);

    expect($transition)->toBe(CircuitState::Open)
        ->and($breaker->permit(NotificationChannel::Sms)->waitMilliseconds)->toBe(30_000);
});

test('lets the next caller probe when the probe never reports back', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);
    $this->travel(30)->seconds();
    $breaker->permit(NotificationChannel::Sms);
    $this->travel(20)->seconds();

    expect($breaker->permit(NotificationChannel::Sms)->granted)->toBeTrue();
});

test('ignores the outcomes of calls that started before the circuit opened', function () {
    $breaker = circuitBreaker();
    openCircuit($breaker, NotificationChannel::Sms);

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, [GatewayOutcome::Accepted, GatewayOutcome::RetryableFailure]);

    expect($transitions)->toBe([null, null])
        ->and($breaker->state(NotificationChannel::Sms))->toBe(CircuitState::Open);
});

test('still counts calls made earlier in the window', function () {
    $breaker = circuitBreaker();
    recordOutcomes($breaker, NotificationChannel::Sms, array_fill(0, 3, GatewayOutcome::RetryableFailure));
    $this->travel(59)->seconds();

    $transitions = recordOutcomes($breaker, NotificationChannel::Sms, [GatewayOutcome::RetryableFailure]);

    expect($transitions)->toBe([CircuitState::Open]);
});

test('refuses to work with a Redis client other than phpredis', function () {
    $redis = Mockery::mock(RedisFactory::class);
    $redis->shouldReceive('connection')->andReturn(Mockery::mock(Connection::class));
    $breaker = new RedisCircuitBreaker($redis, windowSeconds: 60, minimumAttempts: 4, failureRatePercent: 50, cooldownSeconds: 30, probeTimeoutSeconds: 20);

    $breaker->permit(NotificationChannel::Sms);
})->throws(UnexpectedValueException::class, 'The circuit breaker requires the phpredis Redis client.');

test('throws when Redis answers a permit request with an unexpected reply', function () {
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
    $breaker = new RedisCircuitBreaker($redis, windowSeconds: 60, minimumAttempts: 4, failureRatePercent: 50, cooldownSeconds: 30, probeTimeoutSeconds: 20);

    $breaker->permit(NotificationChannel::Sms);
})->throws(UnexpectedValueException::class, 'The circuit breaker returned an unexpected reply.');
