<?php

use App\Enums\CircuitState;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery\MockInterface;
use Tests\Support\FakeCircuitBreaker;

beforeEach(function () {
    config(['notifications.gateway.url' => 'https://gateway.test/notify']);
});

function horizonMasters(array $masters): void
{
    test()->mock(MasterSupervisorRepository::class, function (MockInterface $mock) use ($masters): void {
        $mock->shouldReceive('all')->andReturn($masters);
    });
}

test('reports every dependency healthy with 200 and without a token', function () {
    horizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);

    $response = $this->getJson(route('health'));

    $response->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonPath('checks.horizon.status', 'ok')
        ->assertJsonPath('checks.gateway.status', 'ok')
        ->assertJsonPath('checks.database.message', null)
        ->assertJsonStructure(['status', 'checks', 'checked_at']);
});

test('returns 503 when Horizon is not running', function () {
    horizonMasters([]);

    $response = $this->getJson(route('health'));

    $response->assertServiceUnavailable()
        ->assertJsonPath('status', 'fail')
        ->assertJsonPath('checks.horizon.status', 'fail')
        ->assertJsonPath('checks.database.status', 'ok');
});

test('stays available but reports the service degraded while a delivery circuit is open', function () {
    horizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: ['sms' => CircuitState::Open]));

    $response = $this->getJson(route('health'));

    $response->assertOk()
        ->assertJsonPath('status', 'warn')
        ->assertJsonPath('checks.delivery_circuits.status', 'warn');
});
