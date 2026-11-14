<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

#[Group('Notifications', weight: 1)]
class CancelNotificationController extends Controller
{
    /**
     * Cancel a notification that a worker has not picked up yet.
     *
     * Responds with 409 when the notification is already processing or finished.
     */
    #[Authorize('cancel', 'notification')]
    #[Endpoint(title: 'Cancel a notification')]
    public function __invoke(Notification $notification): NotificationResource
    {
        if (! $notification->cancel()) {
            abort(Response::HTTP_CONFLICT, __('notifications.cannot_be_cancelled', ['status' => $notification->status->value]));
        }

        return NotificationResource::make($notification);
    }
}
