<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('seeds an operator who can sign in, two templates and an API token', function () {
    $this->seed();

    $operator = User::query()->where('email', 'test@example.com')->sole();
    expect($operator->is_operator)->toBeTrue()
        ->and(Hash::check('password', $operator->password))->toBeTrue()
        ->and($operator->notificationTemplates()->orderBy('name')->pluck('channel', 'name')->map->value->all())
        ->toBe(['invoice-ready' => 'email', 'order-shipped' => 'sms'])
        ->and($operator->tokens()->count())->toBe(1);
});

test('runs again without duplicating the operator or the templates, keeping a changed password', function () {
    $this->seed();
    User::query()->where('email', 'test@example.com')->sole()->forceFill(['password' => 'changed-password'])->save();

    $this->seed();

    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('notification_templates', 2);
    expect(Hash::check('changed-password', User::query()->sole()->password))->toBeTrue();
});

test('makes an existing user with the operator email an operator', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->seed();

    expect(User::query()->sole()->is_operator)->toBeTrue();
});

test('prints a demo API token that expires after a week and clears the expired ones', function () {
    $this->travelTo('2026-11-14 12:00:00');
    $this->seed();
    $this->travel(8)->days();

    $this->artisan('db:seed')
        ->expectsOutputToContain('API token for test@example.com (valid for 7 days): ')
        ->assertSuccessful();

    expect(User::query()->sole()->tokens()->pluck('expires_at')->map->toDateTimeString()->all())
        ->toBe(['2026-11-29 12:00:00']);
});

test('creates and prints no API token in production, where the output ends up in the logs', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--force' => true])
        ->doesntExpectOutputToContain('API token')
        ->assertSuccessful();

    expect(User::query()->sole()->tokens()->count())->toBe(0);
});
