<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

/**
 * Redis carries the queues, the rate limiter, the cache and the idempotency locks.
 */
final readonly class RedisHealthCheck implements HealthCheck
{
    public function __construct(private RedisFactory $redis) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthCheckResultData
    {
        $this->redis->connection()->ping();

        return HealthCheckResultData::ok();
    }
}
