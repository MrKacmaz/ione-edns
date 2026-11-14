<?php

use App\Models\Notification;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

test('lets operators operate the delivery system', function () {
    $operator = User::factory()->operator()->make();

    expect(Gate::forUser($operator)->allows('operate'))->toBeTrue();
});

test('keeps users who are not operators out of the operator tools', function () {
    $user = User::factory()->make();

    expect(Gate::forUser($user)->allows('operate'))->toBeFalse();
});

test('keeps guests out of the operator tools', function () {
    expect(Gate::allows('operate'))->toBeFalse();
});

test('hands out immutable dates, so date arithmetic never shifts the original', function () {
    $this->travelTo('2026-11-14 12:00:00');
    $now = now();

    $inAnHour = $now->addHour();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->toDateTimeString()->toBe('2026-11-14 12:00:00');
    expect($inAnHour->toDateTimeString())->toBe('2026-11-14 13:00:00');
});

test('casts model dates to immutable dates', function () {
    $notification = Notification::factory()->make(['sent_at' => '2026-11-14 12:00:00']);

    expect($notification->sent_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('refuses destructive database commands in production', function () {
    config(['database.connections.scratch' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->app->getProvider(AppServiceProvider::class)->boot();

    $this->artisan('db:wipe', ['--database' => 'scratch', '--force' => true])
        ->expectsOutputToContain('This command is prohibited from running in this environment.')
        ->assertFailed();
});

test('allows destructive database commands outside production', function () {
    config(['database.connections.scratch' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $this->artisan('db:wipe', ['--database' => 'scratch', '--force' => true])->assertSuccessful();
});

test('documents every API endpoint as requiring a bearer token', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    $response = $this->getJson(route('scramble.docs.document'));

    $response->assertOk()
        ->assertJsonPath('components.securitySchemes.http', ['type' => 'http', 'scheme' => 'bearer'])
        ->assertJsonPath('security', [['http' => []]]);
});

test('returns 429 with Retry-After once an account has used up its API requests for the minute', function () {
    config(['notifications.api.requests_per_minute' => 2]);
    $this->freezeTime();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.user'))->assertOk();
    $this->getJson(route('api.v1.user'))->assertOk();
    $response = $this->getJson(route('api.v1.user'));

    $response->assertTooManyRequests()->assertHeader('Retry-After', 60);
});

test('accepts API requests again once the minute has passed', function () {
    config(['notifications.api.requests_per_minute' => 1]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson(route('api.v1.user'))->assertOk();
    $this->getJson(route('api.v1.user'))->assertTooManyRequests();

    $this->travel(61)->seconds();

    $this->getJson(route('api.v1.user'))->assertOk();
});

test('shares one API request budget between all tokens of an account', function () {
    config(['notifications.api.requests_per_minute' => 1]);
    $user = User::factory()->create();
    $firstToken = $user->createToken('first')->plainTextToken;
    $secondToken = $user->createToken('second')->plainTextToken;
    $this->withToken($firstToken)->getJson(route('api.v1.user'))->assertOk();
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($secondToken)->getJson(route('api.v1.user'));

    $response->assertTooManyRequests();
});

test('keeps a separate API request budget for every account', function () {
    config(['notifications.api.requests_per_minute' => 1]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson(route('api.v1.user'))->assertOk();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.user'))->assertOk();
});

test('limits WebSocket channel authorization with the same API request budget', function () {
    config(['notifications.api.requests_per_minute' => 1]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->getJson(route('api.v1.user'))->assertOk();

    $response = $this->postJson('/api/v1/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-App.Models.User.{$user->id}",
    ]);

    $response->assertTooManyRequests();
});
