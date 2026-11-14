<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('allows the owner to view, cancel and retry the notification', function (string $ability) {
    $notification = Notification::factory()->create();

    expect(Gate::forUser($notification->user)->inspect($ability, $notification)->allowed())->toBeTrue();
})->with(['view', 'cancel', 'retry']);

test('denies other users as if the notification did not exist', function (string $ability) {
    $notification = Notification::factory()->create();

    $response = Gate::forUser(User::factory()->create())->inspect($ability, $notification);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'cancel', 'retry']);
