<?php

use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Laravel\Sanctum\Sanctum;

/**
 * Authorize channels with the Reverb broadcaster, which signs subscriptions
 * locally without contacting a server, instead of the tests' null broadcaster
 * that grants everything.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    app(BroadcastManager::class)->forgetDrivers();

    require base_path('routes/channels.php');
});

/**
 * @return array{socket_id: string, channel_name: string}
 */
function subscription(string $channel): array
{
    return ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel];
}

test('lets only operators subscribe to the operators channel', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->post('/broadcasting/auth', subscription('operators'))
        ->assertOk()
        ->assertJsonStructure(['auth']);

    $this->actingAs(User::factory()->create())
        ->post('/broadcasting/auth', subscription('operators'))
        ->assertForbidden();
});

test('lets users subscribe to their own channel only', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', subscription('App.Models.User.'.$user->id))
        ->assertOk();

    $this->actingAs($user)
        ->post('/broadcasting/auth', subscription('App.Models.User.'.$otherUser->id))
        ->assertForbidden();
});

test('lets API clients authorize their channel with a Sanctum token', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/broadcasting/auth', subscription('App.Models.User.'.$user->id))
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('refuses API clients without a token', function () {
    $this->postJson('/api/v1/broadcasting/auth', subscription('App.Models.User.1'))
        ->assertUnauthorized();
});

test('refuses dashboard subscriptions without a session with 403', function () {
    $this->post('/broadcasting/auth', subscription('operators'))->assertForbidden();
});

test('refuses with 403 a user channel whose id is not a number', function () {
    $this->actingAs(User::factory()->create())
        ->post('/broadcasting/auth', subscription('App.Models.User.abc'))
        ->assertForbidden();
});
