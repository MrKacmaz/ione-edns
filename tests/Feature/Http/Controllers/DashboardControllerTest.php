<?php

use App\Enums\CircuitState;
use App\Models\Notification;
use App\Models\User;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeCircuitBreaker;

test('sends guests to the sign-in form', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('returns 403 to users who are not operators', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('shows operators the system metrics and their own latest notifications', function () {
    $operator = User::factory()->operator()->create();
    $own = Notification::factory()->for($operator)->sent()->create();
    Notification::factory()->create();
    $this->app->instance(CircuitBreaker::class, new FakeCircuitBreaker(states: ['email' => CircuitState::Open]));

    $response = $this->actingAs($operator)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('metrics.circuits', ['sms' => 'closed', 'email' => 'open', 'push' => 'closed'])
        ->has('metrics.queues', 3)
        ->has('metrics.windows', 2)
        ->has('notifications', 1)
        ->where('notifications.0.id', $own->id)
        ->where('notifications.0.status', 'sent'));
});

test('starts the feed with the operator\'s twenty newest notifications', function () {
    $operator = User::factory()->operator()->create();
    $notifications = Notification::factory()->for($operator)->count(21)->sequence(
        fn ($sequence) => ['created_at' => now()->subMinutes(21 - $sequence->index)],
    )->create();
    $newest = $notifications->last();

    $response = $this->actingAs($operator)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('notifications', 20)
        ->where('notifications.0.id', $newest->id));
});

test('embeds names and notification text in the page without letting them run as markup', function () {
    $operator = User::factory()->operator()->create(['name' => "<script>alert('name')</script>"]);
    Notification::factory()->for($operator)->create([
        'recipient' => "</script><script>alert('recipient')</script>",
        'content' => "<img src=x onerror=alert('content')>",
    ]);

    $response = $this->actingAs($operator)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee("<script>alert('name')</script>", false)
        ->assertDontSee("<script>alert('recipient')</script>", false)
        ->assertDontSee("<img src=x onerror=alert('content')>", false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', "<script>alert('name')</script>")
            ->where('notifications.0.recipient', "</script><script>alert('recipient')</script>")
            ->where('notifications.0.content', "<img src=x onerror=alert('content')>"));
});
