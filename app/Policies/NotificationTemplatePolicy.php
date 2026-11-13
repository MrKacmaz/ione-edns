<?php

namespace App\Policies;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NotificationTemplatePolicy
{
    /**
     * Determine whether the user can view the template.
     */
    public function view(User $user, NotificationTemplate $template): Response
    {
        return $this->ownedBy($user, $template);
    }

    /**
     * Determine whether the user can update the template.
     */
    public function update(User $user, NotificationTemplate $template): Response
    {
        return $this->ownedBy($user, $template);
    }

    /**
     * Determine whether the user can delete the template.
     */
    public function delete(User $user, NotificationTemplate $template): Response
    {
        return $this->ownedBy($user, $template);
    }

    /**
     * Allow the owner and answer everyone else as if the template did not exist.
     */
    private function ownedBy(User $user, NotificationTemplate $template): Response
    {
        return $template->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
