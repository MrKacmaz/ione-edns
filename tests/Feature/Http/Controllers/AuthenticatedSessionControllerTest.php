<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the sign-in form', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
});

test('signs the user in and sends them to the dashboard', function () {
    $user = User::factory()->operator()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('rejects a wrong password', function () {
    $user = User::factory()->create();

    $response = $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);

    $response->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->assertGuest();
});

test('locks the email address out after five failed attempts, even for the right password', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }
    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertSessionHasErrors(['email' => 'Too many login attempts. Please try again in 60 seconds.']);
    $this->assertGuest();
});

test('sends a signed-in user away from the sign-in form', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

test('signs the user out', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('explains which credential is missing', function () {
    $response = $this->from(route('login'))->post(route('login.store'), []);

    $response->assertRedirect(route('login'))->assertSessionHasErrors([
        'email' => 'The email field is required.',
        'password' => 'The password field is required.',
    ]);
    $this->assertGuest();
});

test('rejects a malformed credential', function (array $credentials, string $invalidField, string $message) {
    $response = $this->from(route('login'))->post(route('login.store'), $credentials);

    $response->assertRedirect(route('login'))->assertSessionHasErrors([$invalidField => $message]);
    $this->assertGuest();
})->with([
    'email that is not an address' => [['email' => 'ada', 'password' => 'password'], 'email', 'The email field must be a valid email address.'],
    'email that is not a string' => [['email' => ['ada@example.com'], 'password' => 'password'], 'email', 'The email field must be a string.'],
    'password that is not a string' => [['email' => 'ada@example.com', 'password' => ['password']], 'password', 'The password field must be a string.'],
]);

test('rejects the credentials of a deleted user', function () {
    $user = User::factory()->operator()->trashed()->create();

    $response = $this->from(route('login'))->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('login'))->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    $this->assertGuest();
});

test('sends the user to the page they asked for before signing in', function () {
    $user = User::factory()->operator()->create();

    $response = $this->withSession(['url.intended' => url('/horizon')])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(url('/horizon'));
});

test('remembers the user across browser sessions when asked to', function () {
    $user = User::factory()->operator()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => true]);

    $response->assertCookie(Auth::guard('web')->getRecallerName());
});

test('does not remember the user unless asked to', function () {
    $user = User::factory()->operator()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertCookieMissing(Auth::guard('web')->getRecallerName());
});

test('starts a new session on sign-in, so a session id planted before it is useless', function () {
    $user = User::factory()->operator()->create();
    $plantedSessionId = str_repeat('a', 40);

    $this->withCookie(config('session.cookie'), $plantedSessionId)
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    expect(session()->getId())->not->toBe($plantedSessionId);
});

test('forgets failed attempts once the user signs in', function () {
    $user = User::factory()->operator()->create();
    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));
    foreach (range(1, 4) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('locks out only the email address whose attempts failed', function () {
    $lockedOut = User::factory()->create();
    $user = User::factory()->operator()->create();
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $lockedOut->email, 'password' => 'wrong']);
    }

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('announces the lockout once the attempts run out', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);
    }
    Event::fake([Lockout::class]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong']);

    Event::assertDispatched(Lockout::class);
});

test('sends a signed-in user who signs in again to the dashboard', function () {
    $user = User::factory()->operator()->create();

    $response = $this->actingAs($user)->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('dashboard'));
});

test('forgets the session data on sign-out', function () {
    $response = $this->actingAs(User::factory()->operator()->create())
        ->withSession(['dashboard.channel' => 'sms'])
        ->post(route('logout'));

    $response->assertSessionMissing('dashboard.channel');
});

test('sends a guest who signs out to the sign-in form', function () {
    $this->post(route('logout'))->assertRedirect(route('login'));
});
