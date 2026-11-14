<?php

use App\Models\Notification;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

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
