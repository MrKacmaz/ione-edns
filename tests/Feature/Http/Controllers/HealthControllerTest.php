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

test('keeps answering JSON to clients that accept anything, such as curl', function () {
    horizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);

    $response = $this->get(route('health'), ['Accept' => '*/*']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('status', 'ok');
});

test('shows a browser the status page with every check and useful links', function () {
    horizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);
    config(['services.jaeger.ui_url' => 'http://jaeger.test']);

    $response = $this->get(route('health'), ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('All systems are working')
        ->assertSee(['Database', 'Redis', 'Queue workers', 'Gateway', 'Delivery circuits', 'Delivery channels'])
        ->assertSee(route('dashboard'))
        ->assertSee('http://jaeger.test')
        ->assertSee(route('health', ['format' => 'json']), escape: false);
});

test('answers the status page with 503 and the failing reason when a check fails', function () {
    horizonMasters([]);

    $response = $this->get(route('health'), ['Accept' => 'text/html']);

    $response->assertServiceUnavailable()
        ->assertSee('The service is down')
        ->assertSee(__('health.horizon_inactive'));
});

test('answers JSON to a browser that asks for it with the format parameter', function () {
    horizonMasters([(object) ['name' => 'master-1', 'status' => 'running']]);

    $response = $this->get(route('health', ['format' => 'json']), ['Accept' => 'text/html']);

    $response->assertOk()->assertJsonPath('status', 'ok');
});
