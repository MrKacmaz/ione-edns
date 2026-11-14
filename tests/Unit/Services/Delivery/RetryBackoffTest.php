<?php

use App\Services\Delivery\RetryBackoff;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\ZeroRandomEngine;

test('doubles the wait window per failed attempt up to the cap', function (int $failedAttempts, int $window) {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600);

    expect($backoff->windowAfter($failedAttempts))->toBe($window);
})->with([
    'first failure' => [1, 30],
    'second failure' => [2, 60],
    'third failure' => [3, 120],
    'fourth failure' => [4, 240],
    'fifth failure hits the cap' => [5, 300],
    'far beyond the cap' => [40, 300],
]);

test('waits a random time within the window of the failed attempt', function () {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600, randomizer: new Randomizer(new Mt19937(42)));

    $delays = array_map(fn () => $backoff->delayAfter(3), range(1, 200));

    expect(min($delays))->toBeGreaterThanOrEqual(1)
        ->and(max($delays))->toBeLessThanOrEqual(120)
        ->and(count(array_unique($delays)))->toBeGreaterThan(50);
});

test('waits at least one second even when the random draw is zero', function () {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600, randomizer: new Randomizer(new ZeroRandomEngine));

    expect($backoff->delayAfter(1))->toBe(1);
});

test('never retries before the provider\'s Retry-After', function () {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600, randomizer: new Randomizer(new ZeroRandomEngine));

    expect($backoff->delayAfter(1, retryAfterSeconds: 500))->toBe(500);
});

test('honours the provider\'s Retry-After only up to the configured maximum', function (int $retryAfterSeconds) {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600, randomizer: new Randomizer(new ZeroRandomEngine));

    expect($backoff->delayAfter(1, retryAfterSeconds: $retryAfterSeconds))->toBe(3600);
})->with([
    'two hours' => [7200],
    'the largest int' => [PHP_INT_MAX],
]);

test('uses the base window when no attempt has failed yet', function () {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600);

    expect($backoff->windowAfter(0))->toBe(30);
});

test('keeps spreading retries over the window above a shorter Retry-After', function () {
    $backoff = new RetryBackoff(baseSeconds: 30, capSeconds: 300, maxRetryAfterSeconds: 3600, randomizer: new Randomizer(new Mt19937(42)));

    $delays = array_map(fn () => $backoff->delayAfter(3, retryAfterSeconds: 60), range(1, 200));

    expect(min($delays))->toBe(60)
        ->and(max($delays))->toBeGreaterThan(60)->toBeLessThanOrEqual(120);
});
