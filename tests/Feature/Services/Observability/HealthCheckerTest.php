<?php

use App\Enums\CircuitState;
use App\Enums\HealthCheckStatus;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Observability\HealthChecker;
use Illuminate\Database\QueryException;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery\MockInterface;
use Tests\Support\FakeCircuitBreaker;

beforeEach(function () {
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['name' => 'master-1', 'status' => 'running']]);
    });
});

test('passes every check, in the order the health endpoint reports them, while every dependency is healthy', function () {
    $results = app(HealthChecker::class)->run();

    expect(array_keys($results))->toBe(['database', 'redis', 'horizon', 'gateway', 'delivery_circuits'])
        ->and(array_column($results, 'status'))->each->toBe(HealthCheckStatus::Ok)
        ->and(array_column($results, 'message'))->each->toBeNull();
});

test('fails a check that throws without revealing why, and reports the exception for the logs', function () {
    Exceptions::fake();
    config(['database.connections.missing' => [
        ...config('database.connections.pgsql'),
        'database' => 'edns_health_check_missing_database',
    ]]);

    $results = DB::usingConnection('missing', fn (): array => app(HealthChecker::class)->run());

    expect($results['database'])->toBe(['status' => HealthCheckStatus::Failing, 'message' => 'The database check failed.']);
    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'edns_health_check_missing_database'));
});

test('fails the redis check when Redis cannot be reached', function () {
    Exceptions::fake();
    $unreachable = new RedisManager($this->app, 'phpredis', [
        'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 1],
    ]);

    $results = $this->app->make(HealthChecker::class, ['redis' => $unreachable])->run();

    expect($results['redis'])->toBe(['status' => HealthCheckStatus::Failing, 'message' => 'The redis check failed.']);
    Exceptions::assertReported(RedisException::class);
});

test('checks that an unpaused Horizon master supervisor runs, because nothing is delivered otherwise', function (array $masters, HealthCheckStatus $status, ?string $message) {
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($masters): void {
        $mock->shouldReceive('all')->andReturn($masters);
    });

    $result = app(HealthChecker::class)->run()['horizon'];

    expect($result)->toBe(['status' => $status, 'message' => $message]);
})->with([
    'one master runs' => [[(object) ['status' => 'running']], HealthCheckStatus::Ok, null],
    'one of several masters runs' => [[(object) ['status' => 'paused'], (object) ['status' => 'running']], HealthCheckStatus::Ok, null],
    'no master runs' => [[], HealthCheckStatus::Failing, 'Horizon is not running, so no notification is being delivered.'],
    'every master is paused' => [[(object) ['status' => 'paused']], HealthCheckStatus::Failing, 'Horizon is paused, so no notification is being delivered.'],
]);

test('fails without calling the gateway when its URL is not configured', function (?string $url) {
    config(['notifications.gateway.url' => $url]);

    $result = app(HealthChecker::class)->run()['gateway'];

    expect($result)->toBe(['status' => HealthCheckStatus::Failing, 'message' => 'The notification gateway URL is not configured.']);
})->with([
    'missing' => [null],
    'empty' => [''],
]);

test('reports the service degraded and names every channel whose circuit is not closed', function (array $states, string $channels) {
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: $states));

    $result = app(HealthChecker::class)->run()['delivery_circuits'];

    expect($result)->toBe([
        'status' => HealthCheckStatus::Degraded,
        'message' => "Deliveries are paused on these channels until their provider recovers: {$channels}.",
    ]);
})->with([
    'one circuit open' => [['email' => CircuitState::Open], 'email'],
    'one circuit half open' => [['email' => CircuitState::HalfOpen], 'email'],
    'several circuits, named in channel order' => [['push' => CircuitState::Open, 'sms' => CircuitState::Open, 'email' => CircuitState::HalfOpen], 'sms, email, push'],
]);

test('rates the service by its worst check', function (array $statuses, HealthCheckStatus $overallStatus) {
    $results = array_map(fn (HealthCheckStatus $status): array => ['status' => $status, 'message' => null], $statuses);

    expect(HealthChecker::overallStatus($results))->toBe($overallStatus);
})->with([
    'every check passes' => [['database' => HealthCheckStatus::Ok, 'redis' => HealthCheckStatus::Ok], HealthCheckStatus::Ok],
    'one check degraded' => [['database' => HealthCheckStatus::Ok, 'delivery_circuits' => HealthCheckStatus::Degraded], HealthCheckStatus::Degraded],
    'one check failing' => [['database' => HealthCheckStatus::Ok, 'horizon' => HealthCheckStatus::Failing], HealthCheckStatus::Failing],
    'a failing check outweighs a degraded one' => [['delivery_circuits' => HealthCheckStatus::Degraded, 'horizon' => HealthCheckStatus::Failing], HealthCheckStatus::Failing],
]);
