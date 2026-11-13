<?php

namespace App\Http\Controllers;

use App\Actions\DispatchNotificationsAction;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

class RetryNotificationController extends Controller
{
    /**
     * Send a failed notification again with a fresh set of delivery attempts.
     *
     * Earlier attempts stay in the delivery history. Responds with 409 when the
     * notification has not failed.
     */
    #[Authorize('retry', 'notification')]
    public function __invoke(Notification $notification, DispatchNotificationsAction $dispatchNotifications): JsonResponse
    {
        if (! $notification->resetForRedelivery()) {
            abort(Response::HTTP_CONFLICT, __('notifications.cannot_be_retried', ['status' => $notification->status->value]));
        }

        $dispatchNotifications->handle(new Collection([$notification]));

        /**
         * The notification is queued for delivery again.
         *
         * @status 202
         */
        return NotificationResource::make($notification)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
