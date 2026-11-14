<?php

namespace App\Http\Controllers;

use App\Enums\NotificationStatus;
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
     * Cancelling a notification that is already cancelled succeeds again and
     * returns it unchanged, so a client may safely repeat the request. Responds
     * with 409 when the notification is already processing, sent or failed.
     */
    #[Authorize('cancel', 'notification')]
    #[Endpoint(title: 'Cancel a notification')]
    public function __invoke(Notification $notification): NotificationResource
    {
        if (! $notification->cancel() && $notification->status !== NotificationStatus::Cancelled) {
            abort(Response::HTTP_CONFLICT, __('notifications.cannot_be_cancelled', ['status' => $notification->status->value]));
        }

        return NotificationResource::make($notification);
    }
}
