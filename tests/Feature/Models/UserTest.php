<?php

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

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

test('creates a user who is not an operator, so the operator gate refuses them without a reload', function () {
    $user = User::factory()->create();

    $canOperate = Gate::forUser($user)->allows('operate');

    expect($canOperate)->toBeFalse();
});

test('ignores an operator flag passed in mass-assigned attributes', function () {
    $user = User::create([
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'a-long-enough-password',
        'is_operator' => true,
    ]);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'is_operator' => false]);
});

test('exposes neither the password hash nor the remember token through the API', function () {
    $user = User::factory()->create();
    $token = $user->createToken('integration')->plainTextToken;

    $response = $this->withToken($token)->getJson(route('api.v1.user'));

    $response->assertOk();
    expect(array_keys($response->json()))->toEqualCanonicalizing([
        'id',
        'name',
        'email',
        'email_verified_at',
        'is_operator',
        'created_at',
        'updated_at',
        'deleted_at',
    ]);
});
