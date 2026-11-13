<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| The dashboard authorizes at /broadcasting/auth with its session; API
| clients authorize at /api/v1/broadcasting/auth with their Sanctum token.
|
*/

// A user's own notifications reaching a final status.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->id === $id;
});

// Metrics snapshots and circuit changes for the operator dashboard.
Broadcast::channel('operators', function (User $user): bool {
    return Gate::forUser($user)->allows('operate');
});
