<?php

namespace App\Policies;

use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotificationBatchPolicy
{
    /**
     * Determine whether the user can view the batch.
     */
    public function view(User $user, NotificationBatch $batch): Response
    {
        return $this->ownedBy($user, $batch);
    }

    /**
     * Determine whether the user can cancel the batch's pending notifications.
     */
    public function cancel(User $user, NotificationBatch $batch): Response
    {
        return $this->ownedBy($user, $batch);
    }

    /**
     * Allow the owner and answer everyone else as if the batch did not exist.
     */
    private function ownedBy(User $user, NotificationBatch $batch): Response
    {
        return $batch->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
