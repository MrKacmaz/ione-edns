<?php

namespace App\Http\Controllers;

use App\Actions\DispatchNotificationsAction;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Group('Notifications', weight: 1)]
class RetryNotificationController extends Controller
{
    /**
     * Send a failed notification again with a fresh set of delivery attempts.
     *
     * Earlier attempts stay in the delivery history. Responds with 409 when the
     * notification has not failed.
     */
    #[Authorize('retry', 'notification')]
    #[Endpoint(title: 'Retry a failed notification')]
    public function __invoke(Notification $notification, DispatchNotificationsAction $dispatchNotifications): JsonResponse
    {
        if (! $notification->resetForRedelivery()) {
            abort(Response::HTTP_CONFLICT, __('notifications.cannot_be_retried', ['status' => $notification->status->value]));
        }

        // The notification is already waiting again, so a queue outage must not turn the
        // answer into an error: the stale sweep pushes waiting notifications within minutes.
        try {
            $dispatchNotifications->handle(new Collection([$notification]));
        } catch (Throwable $exception) {
            report($exception);
        }

        /**
         * The notification is queued for delivery again.
         *
         * @status 202
         */
        return NotificationResource::make($notification)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
