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
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

#[Group('Notifications', 'Create, list, read, cancel and retry notifications.', weight: 1)]
class NotificationController extends Controller
{
    /**
     * List the authenticated user's notifications, newest first.
     */
    #[Endpoint(title: 'List notifications', description: 'The authenticated user\'s notifications, newest first, with optional filters and cursor pagination.')]
    public function index(ListNotificationsRequest $request, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        $notifications = $user->notifications()
            ->when($request->status(), fn (Builder $q, NotificationStatus $status) => $q->where('status', $status))
            ->when($request->channel(), fn (Builder $q, NotificationChannel $channel) => $q->where('channel', $channel))
            ->when($request->batchId(), fn (Builder $q, string $batchId) => $q->where('batch_id', $batchId))
            // Formatted by the model, so the bounds keep their microseconds.
            ->when($request->createdFrom(), fn (Builder $q, CarbonInterface $from) => $q->where('created_at', '>=', $q->getModel()->fromDateTime($from)))
            ->when($request->createdTo(), fn (Builder $q, CarbonInterface $to) => $q->where('created_at', '<=', $q->getModel()->fromDateTime($to)))
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
    #[HeaderParameter('Idempotency-Key', 'Makes a retried request safe: a repeat with the same key and payload within 24 hours replays the first response instead of creating notifications again. A different payload with the same key answers 422.', required: false, type: 'string', example: '8d3f1c7e-2b4a-4f0e-9c61-5a7b2e9d1f30')]
    #[Header('Idempotent-Replayed', 'Present with the value "true" when the response is a replay of an earlier request with the same Idempotency-Key.', type: 'string', status: 202)]
    #[DocumentedResponse(400, 'The Idempotency-Key header is empty or longer than 255 characters.', type: 'array{message: string}')]
    #[DocumentedResponse(409, 'A request with the same Idempotency-Key is still being processed. Retry later.', type: 'array{message: string}')]
    #[Endpoint(title: 'Create a notification')]
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
    #[Endpoint(title: 'Get a notification', description: 'The notification and its delivery status. Another user\'s notification answers 404.')]
    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification);
    }
}
