<?php

namespace App\Http\Controllers;

use App\Actions\CreateNotificationsAction;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationBatch;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as DocumentedResponse;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Symfony\Component\HttpFoundation\Response;

#[Group('Batches', 'Create up to 1000 notifications at once and follow them as a batch.', weight: 2)]
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
    #[HeaderParameter('Idempotency-Key', 'Makes a retried request safe: a repeat with the same key and payload within 24 hours replays the first response instead of creating notifications again. A different payload with the same key answers 422.', required: false, type: 'string', example: '8d3f1c7e-2b4a-4f0e-9c61-5a7b2e9d1f30')]
    #[Header('Idempotent-Replayed', 'Present with the value "true" when the response is a replay of an earlier request with the same Idempotency-Key.', type: 'string', status: 202)]
    #[DocumentedResponse(400, 'The Idempotency-Key header is empty or longer than 255 characters.', type: 'array{message: string}')]
    #[DocumentedResponse(409, 'A request with the same Idempotency-Key is still being processed. Retry later.', type: 'array{message: string}')]
    #[Endpoint(title: 'Create a batch')]
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
    #[Endpoint(title: 'Get a batch', description: 'How many of the batch\'s notifications are in each status, and whether all of them are finished.')]
    public function show(NotificationBatch $batch): NotificationBatchResource
    {
        return NotificationBatchResource::make($batch);
    }
}
