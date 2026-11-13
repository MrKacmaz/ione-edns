<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

class CancelNotificationController extends Controller
{
    /**
     * Cancel a notification that a worker has not picked up yet.
     *
     * Responds with 409 when the notification is already processing or finished.
     */
    #[Authorize('cancel', 'notification')]
    public function __invoke(Notification $notification): NotificationResource
    {
        if (! $notification->cancel()) {
            abort(Response::HTTP_CONFLICT, __('notifications.cannot_be_cancelled', ['status' => $notification->status->value]));
        }

        return NotificationResource::make($notification);
    }
}
