<?php

namespace App\Http\Controllers;

use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Container\Attributes\CurrentUser;

#[Group('Account', 'The user the token belongs to.', weight: 4)]
class CurrentUserController extends Controller
{
    /**
     * Show the user the token belongs to.
     *
     * Its id names the private WebSocket channel `App.Models.User.{id}` that
     * carries the user's notification status updates.
     */
    #[Endpoint(title: 'Get the current user')]
    public function __invoke(#[CurrentUser] User $user): User
    {
        return $user;
    }
}
