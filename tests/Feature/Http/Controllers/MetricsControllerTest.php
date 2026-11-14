<?php

use App\Models\Notification;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['notifications.metrics.cache_seconds' => 0]);
});

test('returns 401 when no token is provided', function () {
    $this->getJson(route('api.v1.metrics'))->assertUnauthorized();
});

test('returns 403 to users who are not operators, because the numbers cover every user', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.metrics'))->assertForbidden();
});

test('reports the collected metrics', function () {
    Sanctum::actingAs(User::factory()->operator()->create());
    Notification::factory()->queued()->create();

    $response = $this->getJson(route('api.v1.metrics'));

    $response->assertOk()->assertJsonPath('data.notifications_in_flight', [
        'waiting' => 0,
        'scheduled' => 0,
        'queued' => 1,
        'processing' => 0,
    ]);
});

test('serves the cached snapshot until it expires', function () {
    config(['notifications.metrics.cache_seconds' => 60]);
    Sanctum::actingAs(User::factory()->operator()->create());
    $this->getJson(route('api.v1.metrics'));
    Notification::factory()->queued()->create();

    $cached = $this->getJson(route('api.v1.metrics'));
    $this->travel(61)->seconds();
    $fresh = $this->getJson(route('api.v1.metrics'));

    $cached->assertJsonPath('data.notifications_in_flight.queued', 0);
    $fresh->assertJsonPath('data.notifications_in_flight.queued', 1);
});
