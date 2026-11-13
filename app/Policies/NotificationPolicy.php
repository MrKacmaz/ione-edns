<?php

namespace App\Policies;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotificationPolicy
{
    /**
     * Determine whether the user can view the notification.
     */
    public function view(User $user, Notification $notification): Response
    {
        return $this->ownedBy($user, $notification);
    }

    /**
     * Determine whether the user can cancel the notification.
     */
    public function cancel(User $user, Notification $notification): Response
    {
        return $this->ownedBy($user, $notification);
    }

    /**
     * Allow the owner and answer everyone else as if the notification did not exist.
     */
    private function ownedBy(User $user, Notification $notification): Response
    {
        return $notification->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
