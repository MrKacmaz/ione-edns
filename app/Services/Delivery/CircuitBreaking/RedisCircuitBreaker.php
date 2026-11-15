<?php

namespace App\Services\Delivery\CircuitBreaking;

use App\Data\CircuitPermitData;
use App\Enums\CircuitState;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use UnexpectedValueException;

/**
 * A per-channel circuit breaker shared by every worker through Redis.
 *
 * While closed, each call's outcome is counted in one-second buckets; once the
 * calls of the sliding window reach the minimum and their retryable failure
 * rate the threshold, the circuit opens. After the cooldown the first caller
 * becomes the probe (half open) and everyone else keeps waiting; the probe's
 * outcome closes or reopens the circuit. Permanent failures count as healthy
 * answers, because the provider did respond.
 *
 * Each transition happens inside one atomic script, so exactly one caller
 * learns that the circuit opened or closed. The scripts take the time from
 * the application clock, so tests can travel through the cooldown; clock
 * differences of a few milliseconds between workers do not matter here.
 * Requires the phpredis client.
 */
final readonly class RedisCircuitBreaker implements CircuitBreaker
{
    /**
     * KEYS[1] the channel's state hash.
     * ARGV[1] now in milliseconds, ARGV[2] the probe timeout in milliseconds.
     *
     * Returns {1, 0} when the call may go ahead, {1, 0, lease} when it goes ahead as
     * the probe, and {0, wait} otherwise.
     */
    private const string PERMIT_SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local state = redis.call('HGET', KEYS[1], 'state') or 'closed'

        if state == 'closed' then
            return {1, 0}
        end

        if state == 'open' then
            local open_until = tonumber(redis.call('HGET', KEYS[1], 'open_until') or 0)
            if now < open_until then
                return {0, open_until - now}
            end
        else
            local probe_until = tonumber(redis.call('HGET', KEYS[1], 'probe_until') or 0)
            if now < probe_until then
                return {0, probe_until - now}
            end
        end

        local probe_until = string.format('%.0f', now + tonumber(ARGV[2]))
        redis.call('HSET', KEYS[1], 'state', 'half_open', 'probe_until', probe_until)

        return {1, 0, probe_until}
        LUA;

    /**
     * KEYS[1] the channel's state hash.
     * ARGV[1] the lease of the probe that is given back.
     *
     * Returns 1 when the probe was given back and 0 when it was not held anymore.
     */
    private const string RELEASE_PROBE_SCRIPT = <<<'LUA'
        if redis.call('HGET', KEYS[1], 'state') ~= 'half_open' or redis.call('HGET', KEYS[1], 'probe_until') ~= ARGV[1] then
            return 0
        end

        redis.call('HDEL', KEYS[1], 'probe_until')

        return 1
        LUA;

    /**
     * KEYS[1] the channel's state hash, KEYS[2] the channel's outcome buckets.
     * ARGV[1] now in milliseconds, ARGV[2] 1 for a retryable failure and 0 otherwise,
     * ARGV[3] the window in seconds, ARGV[4] the minimum calls, ARGV[5] the failure
     * rate in percent, ARGV[6] the cooldown in milliseconds.
     *
     * Returns the state the circuit moved to, or false when it did not change.
     */
    private const string RECORD_SCRIPT = <<<'LUA'
        local now = tonumber(ARGV[1])
        local failed = ARGV[2] == '1'
        local open_until = string.format('%.0f', now + tonumber(ARGV[6]))
        local state = redis.call('HGET', KEYS[1], 'state') or 'closed'

        if state == 'half_open' then
            if failed then
                redis.call('HSET', KEYS[1], 'state', 'open', 'open_until', open_until)
                redis.call('HDEL', KEYS[1], 'probe_until')
                return 'open'
            end

            redis.call('DEL', KEYS[1], KEYS[2])
            return 'closed'
        end

        -- Calls that started before the circuit opened change nothing.
        if state == 'open' then
            return false
        end

        local window = tonumber(ARGV[3])
        local second = math.floor(now / 1000)
        redis.call('HINCRBY', KEYS[2], second .. ':total', 1)
        if failed then
            redis.call('HINCRBY', KEYS[2], second .. ':failed', 1)
        end
        redis.call('EXPIRE', KEYS[2], window * 2)

        local total, failures = 0, 0
        local buckets = redis.call('HGETALL', KEYS[2])
        for i = 1, #buckets, 2 do
            local bucket, kind = string.match(buckets[i], '^(%d+):(%a+)$')
            if tonumber(bucket) <= second - window then
                redis.call('HDEL', KEYS[2], buckets[i])
            elseif kind == 'total' then
                total = total + tonumber(buckets[i + 1])
            else
                failures = failures + tonumber(buckets[i + 1])
            end
        end

        if total >= tonumber(ARGV[4]) and failures * 100 >= total * tonumber(ARGV[5]) then
            redis.call('HSET', KEYS[1], 'state', 'open', 'open_until', open_until)
            redis.call('DEL', KEYS[2])
            return 'open'
        end

        return false
        LUA;

    public function __construct(
        private RedisFactory $redis,
        #[Config('notifications.circuit_breaker.window_seconds')] private int $windowSeconds,
        #[Config('notifications.circuit_breaker.minimum_attempts')] private int $minimumAttempts,
        #[Config('notifications.circuit_breaker.failure_rate_percent')] private int $failureRatePercent,
        #[Config('notifications.circuit_breaker.cooldown_seconds')] private int $cooldownSeconds,
        #[Config('notifications.circuit_breaker.probe_timeout_seconds')] private int $probeTimeoutSeconds,
    ) {}

    /**
     * Ask whether the channel's provider may be called now.
     */
    public function permit(NotificationChannel $channel): CircuitPermitData
    {
        $result = $this->connection()->eval(
            self::PERMIT_SCRIPT,
            1,
            self::stateKey($channel),
            (string) now()->getTimestampMs(),
            (string) ($this->probeTimeoutSeconds * 1000),
        );

        if (! is_array($result) || ! in_array(count($result), [2, 3], true)) {
            throw new UnexpectedValueException(__('notifications.errors.circuit_breaker_reply'));
        }

        return match (true) {
            (int) $result[0] !== 1 => CircuitPermitData::denied((int) $result[1]),
            isset($result[2]) => CircuitPermitData::probe((string) $result[2]),
            default => CircuitPermitData::granted(),
        };
    }

    /**
     * Give back a probe permit whose holder did not report an outcome.
     */
    public function releaseProbe(NotificationChannel $channel, CircuitPermitData $permit): void
    {
        if (! $permit->isProbe()) {
            return;
        }

        $this->connection()->eval(self::RELEASE_PROBE_SCRIPT, 1, self::stateKey($channel), (string) $permit->probeLease);
    }

    /**
     * Record how the channel's provider answered a call.
     *
     * @return CircuitState::Open|CircuitState::Closed|null
     */
    public function record(NotificationChannel $channel, GatewayOutcome $outcome): ?CircuitState
    {
        $result = $this->connection()->eval(
            self::RECORD_SCRIPT,
            2,
            self::stateKey($channel),
            self::bucketsKey($channel),
            (string) now()->getTimestampMs(),
            $outcome === GatewayOutcome::RetryableFailure ? '1' : '0',
            (string) $this->windowSeconds,
            (string) $this->minimumAttempts,
            (string) $this->failureRatePercent,
            (string) ($this->cooldownSeconds * 1000),
        );

        return match ($result) {
            'open' => CircuitState::Open,
            'closed' => CircuitState::Closed,
            default => null,
        };
    }

    /**
     * Get the current state of the channel's circuit.
     */
    public function state(NotificationChannel $channel): CircuitState
    {
        $state = $this->connection()->hget(self::stateKey($channel), 'state');

        return is_string($state) ? CircuitState::from($state) : CircuitState::Closed;
    }

    /**
     * Get the Redis key of the hash that holds the channel's circuit state.
     *
     * Both keys of a channel share a hash tag, so a Redis Cluster keeps them on one node.
     */
    public static function stateKey(NotificationChannel $channel): string
    {
        return 'notifications:circuit:{'.$channel->value.'}:state';
    }

    /**
     * Get the Redis key of the hash that counts the channel's recent outcomes per second.
     */
    public static function bucketsKey(NotificationChannel $channel): string
    {
        return 'notifications:circuit:{'.$channel->value.'}:buckets';
    }

    private function connection(): PhpRedisConnection
    {
        $connection = $this->redis->connection();

        if (! $connection instanceof PhpRedisConnection) {
            throw new UnexpectedValueException(__('notifications.errors.circuit_breaker_client'));
        }

        return $connection;
    }
}
