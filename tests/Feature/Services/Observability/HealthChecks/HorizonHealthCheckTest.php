<?php

use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecks\HorizonHealthCheck;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery\MockInterface;

test('passes while a Horizon master supervisor runs', function () {
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['name' => 'master-1', 'status' => 'running']]);
    });

    $result = app(HorizonHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Ok)
        ->and($result->message)->toBeNull();
});

test('fails when no Horizon master supervisor runs, because nothing is delivered then', function () {
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([]);
    });

    $result = app(HorizonHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Failing)
        ->and($result->message)->toBe('Horizon is not running, so no notification is being delivered.');
});

test('fails while every Horizon master supervisor is paused, because nothing is delivered then', function () {
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([(object) ['name' => 'master-1', 'status' => 'paused']]);
    });

    $result = app(HorizonHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Failing)
        ->and($result->message)->toBe('Horizon is paused, so no notification is being delivered.');
});

test('passes while at least one of several Horizon master supervisors runs', function () {
    $this->mock(MasterSupervisorRepository::class, function (MockInterface $mock): void {
        $mock->shouldReceive('all')->andReturn([
            (object) ['name' => 'master-1', 'status' => 'paused'],
            (object) ['name' => 'master-2', 'status' => 'running'],
        ]);
    });

    expect(app(HorizonHealthCheck::class)->check()->status)->toBe(HealthCheckStatus::Ok);
});
