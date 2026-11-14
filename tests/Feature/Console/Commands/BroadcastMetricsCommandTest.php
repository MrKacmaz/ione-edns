<?php

use App\Enums\CircuitState;
use App\Events\MetricsUpdated;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeCircuitBreaker;

test('broadcasts a fresh metrics snapshot to the operators', function () {
    Event::fake([MetricsUpdated::class]);
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: ['push' => CircuitState::HalfOpen]));

    $this->artisan('metrics:broadcast')->assertSuccessful();

    Event::assertDispatched(MetricsUpdated::class, fn (MetricsUpdated $event): bool => $event->snapshot->circuits['push'] === 'half_open'
        && count($event->snapshot->queues) === 3);
});
