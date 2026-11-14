<?php

namespace App\Services\Delivery\RateLimiting;

use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use UnexpectedValueException;

/**
 * A per-channel rate limiter based on the Generic Cell Rate Algorithm (GCRA).
 *
 * Every channel keeps a "theoretical arrival time" (TAT) in Redis: the moment its
 * next send slot opens. Each reservation takes that slot and moves the TAT one
 * emission interval (1 second / limit) further, so sends are spaced evenly and no
 * one-second window ever holds more than the limit, unlike a fixed-window counter
 * that lets up to twice the limit through around a window boundary.
 *
 * The script runs atomically on Redis and reads Redis' own clock, so every worker
 * shares one schedule regardless of its local clock. It requires the phpredis client.
 */
final readonly class RedisGcraRateLimiter implements ChannelRateLimiter
{
    /**
     * KEYS[1] the channel's TAT in microseconds.
     * ARGV[1] the emission interval in microseconds.
     * ARGV[2] the longest wait in microseconds a caller accepts.
     *
     * Returns {1, wait} when a slot was reserved and {0, wait} when the next slot
     * opens later than the caller accepts.
     */
    private const string SCRIPT = <<<'LUA'
        local clock = redis.call('TIME')
        local now = tonumber(clock[1]) * 1000000 + tonumber(clock[2])
        local interval = tonumber(ARGV[1])
        local max_wait = tonumber(ARGV[2])

        local tat = tonumber(redis.call('GET', KEYS[1]) or now)
        if tat < now then
            tat = now
        end

        local wait = tat - now
        if wait > max_wait then
            return {0, wait}
        end

        local next_tat = tat + interval
        local ttl_ms = math.ceil((next_tat - now) / 1000) + 1000
        redis.call('SET', KEYS[1], string.format('%.0f', next_tat), 'PX', ttl_ms)

        return {1, wait}
        LUA;

    public function __construct(
        private RedisFactory $redis,
        #[Config('notifications.delivery.rate_limit_per_second')] private int $perSecond,
        #[Config('notifications.delivery.rate_limit_max_wait_ms')] private int $maxWaitMilliseconds,
    ) {}

    /**
     * Reserve the next send slot of the channel.
     */
    public function reserve(NotificationChannel $channel): RateLimitReservationData
    {
        $connection = $this->redis->connection();

        if (! $connection instanceof PhpRedisConnection) {
            throw new UnexpectedValueException(__('notifications.errors.rate_limiter_client'));
        }

        $result = $connection->eval(
            self::SCRIPT,
            1,
            self::key($channel),
            (string) self::emissionIntervalMicroseconds($this->perSecond),
            (string) ($this->maxWaitMilliseconds * 1000),
        );

        if (! is_array($result) || count($result) !== 2) {
            throw new UnexpectedValueException(__('notifications.errors.rate_limiter_reply'));
        }

        $waitMilliseconds = (int) ceil((int) $result[1] / 1000);

        return (int) $result[0] === 1
            ? RateLimitReservationData::granted($waitMilliseconds)
            : RateLimitReservationData::denied($waitMilliseconds);
    }

    /**
     * Get the time between two sends of a channel, in microseconds.
     *
     * Rounded up: a rounded-down interval would fit one send too many into a second
     * for limits that do not divide a second evenly (3 per second: 333,333 µs apart,
     * so the fourth send would still land inside the first second).
     */
    public static function emissionIntervalMicroseconds(int $perSecond): int
    {
        return intdiv(1_000_000 + $perSecond - 1, $perSecond);
    }

    /**
     * Get the Redis key that holds the channel's theoretical arrival time.
     */
    public static function key(NotificationChannel $channel): string
    {
        return 'notifications:rate-limit:'.$channel->value;
    }
}
