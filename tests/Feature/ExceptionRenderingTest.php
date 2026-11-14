<?php

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

/**
 * Register an API route that fails with the given error.
 */
function failingApiRoute(Throwable $error): string
{
    Route::middleware('api')->get('api/v1/failing', fn () => throw $error);

    return '/api/v1/failing';
}

test('answers 503 with Retry-After while the database or Redis cannot be reached, and still reports the outage', function (Throwable $outage) {
    Exceptions::fake();

    $response = $this->getJson(failingApiRoute($outage));

    $response->assertServiceUnavailable()
        ->assertHeader('Retry-After', 10)
        ->assertJsonPath('message', 'The service is temporarily unavailable. Retry the request in a few seconds.');
    Exceptions::assertReported($outage::class);
})->with([
    'Redis unreachable' => fn () => new RedisException('Connection refused'),
    'database unreachable' => fn () => queryException('08006', 'connection to server at "postgres", port 5432 failed: Connection refused'),
]);

test('keeps answering 500 for any other error', function () {
    Exceptions::fake();

    $response = $this->getJson(failingApiRoute(queryException('42703', 'column "statuss" does not exist')));

    $response->assertInternalServerError()->assertHeaderMissing('Retry-After');
});
