<?php

namespace App\Http\Controllers;

use App\Actions\CreateNotificationsAction;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

class NotificationBatchController extends Controller
{
    /**
     * Accept a batch of notifications for asynchronous delivery.
     *
     * A batch holds at most `notifications.batch.max_size` notifications (1000 by default).
     * `notifications` lists the result for every requested notification in request
     * order; a notification whose idempotency key was already used is returned as the
     * existing notification and keeps its original batch.
     */
    public function store(
        StoreNotificationBatchRequest $request,
        #[CurrentUser] User $user,
        CreateNotificationsAction $createNotifications,
    ): JsonResponse {
        ['batch' => $batch, 'notifications' => $notifications] = $createNotifications->handleBatch($user, $request->newNotifications());

        return NotificationBatchResource::make($batch)
            ->additional(['notifications' => NotificationResource::collection($notifications)])
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Show how many of the batch's notifications are in each status.
     */
    #[Authorize('view', 'batch')]
    public function show(NotificationBatch $batch): NotificationBatchResource
    {
        return NotificationBatchResource::make($batch);
    }
}
