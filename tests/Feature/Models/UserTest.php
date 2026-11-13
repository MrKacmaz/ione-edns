<?php

use App\Models\Notification;
use App\Models\User;

test('keeps a deleted user and their notifications in the database', function () {
    $user = User::factory()->create();
    $notification = Notification::factory()->for($user)->sent()->create();

    $user->delete();

    $this->assertSoftDeleted($user);
    $this->assertModelExists($notification);
});

test('rejects the API token of a deleted user with 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration')->plainTextToken;
    $user->delete();

    $response = $this->withToken($token)->getJson(route('api.v1.notifications.index'));

    $response->assertUnauthorized();
});

test('accepts the API token again once the user is restored', function () {
    $user = User::factory()->trashed()->create();
    $token = $user->createToken('integration')->plainTextToken;
    $user->restore();

    $response = $this->withToken($token)->getJson(route('api.v1.notifications.index'));

    $response->assertOk();
});
