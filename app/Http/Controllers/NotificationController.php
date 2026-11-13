<?php

namespace App\Http\Controllers;

use App\Actions\CreateNotificationsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications, newest first.
     */
    public function index(ListNotificationsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $notifications = $user->notifications()
            ->when($request->status(), fn (Builder $q, NotificationStatus $status) => $q->where('status', $status))
            ->when($request->channel(), fn (Builder $q, NotificationChannel $channel) => $q->where('channel', $channel))
            ->when($request->batchId(), fn (Builder $q, string $batchId) => $q->where('batch_id', $batchId))
            ->when($request->createdFrom(), fn (Builder $q, CarbonInterface $from) => $q->where('created_at', '>=', $from))
            ->when($request->createdTo(), fn (Builder $q, CarbonInterface $to) => $q->where('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * Accept a notification for asynchronous delivery.
     *
     * Responds with 202 when the notification is created, or with 200 and the
     * existing notification when its idempotency key was already used.
     */
    public function store(StoreNotificationRequest $request, #[CurrentUser] User $user, CreateNotificationsAction $createNotifications): JsonResponse
    {
        $notification = $createNotifications->handle($user, [$request->newNotification()])->sole();

        if (! $notification->wasRecentlyCreated) {
            // The idempotency key was already used; the existing notification is returned.
            return NotificationResource::make($notification)->response();
        }

        // The notification was accepted for asynchronous delivery.
        return NotificationResource::make($notification)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * Show the notification and its delivery status.
     */
    #[Authorize('view', 'notification')]
    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification);
    }
}
