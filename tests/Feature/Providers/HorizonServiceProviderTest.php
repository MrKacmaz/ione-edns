<?php

use App\Models\User;

beforeEach(function () {
    // Horizon lets everyone in locally by default; the application removes that exception.
    $this->app->detectEnvironment(fn (): string => 'local');
});

test('opens Horizon to operators', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->get('/horizon')
        ->assertOk();
});

test('keeps Horizon closed to other users and guests, also in the local environment', function () {
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();

    auth()->logout();

    $this->get('/horizon')->assertForbidden();
});
