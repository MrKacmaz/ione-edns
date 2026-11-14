<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shares the application name with every page', function () {
    config(['app.name' => 'Notification Center']);

    $response = $this->get(route('login'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('name', 'Notification Center'));
});

test('shares no user with a guest', function () {
    $response = $this->get(route('login'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
});

test('shares the signed-in user without their password or remember token', function () {
    $operator = User::factory()->operator()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $response = $this->actingAs($operator->fresh())->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('auth.user', fn (Assert $user) => $user
        ->where('id', $operator->id)
        ->where('name', 'Ada Lovelace')
        ->where('email', 'ada@example.com')
        ->where('is_operator', true)
        ->hasAll(['email_verified_at', 'created_at', 'updated_at', 'deleted_at'])
        ->missing('password')
        ->missing('remember_token')));
});
