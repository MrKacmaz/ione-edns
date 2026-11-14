<?php

use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecks\RedisHealthCheck;
use Illuminate\Redis\RedisManager;

test('passes while Redis answers', function () {
    $result = app(RedisHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Ok)
        ->and($result->message)->toBeNull();
});

test('throws when Redis cannot be reached, which counts as a failed check', function () {
    $unreachable = new RedisManager($this->app, 'phpredis', [
        'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 1],
    ]);

    (new RedisHealthCheck($unreachable))->check();
})->throws(RedisException::class);
