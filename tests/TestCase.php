<?php

namespace Tests;

use App\Services\Delivery\Contracts\CircuitBreaker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\FakeCircuitBreaker;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A test that reaches a real provider is a bug: every HTTP call must be faked.
        Http::preventStrayRequests();

        // Pages render without built frontend assets, so tests need no `npm run build`.
        $this->withoutVite();

        // No test waits for real: failed sign-ins, for example, sleep through Laravel's Timebox.
        // Tests that care about a wait assert it with Sleep::assertSlept().
        Sleep::fake();

        // The real circuit breaker keeps its state in Redis across tests, so failures
        // simulated by one test could open a circuit for the next. Tests that cover
        // the breaker itself build the Redis implementation directly.
        $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker);
    }
}
