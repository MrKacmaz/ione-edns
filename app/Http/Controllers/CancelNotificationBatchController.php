<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Routing\Attributes\Controllers\Authorize;

#[Group('Batches', weight: 2)]
class CancelNotificationBatchController extends Controller
{
    /**
     * Cancel every notification in the batch that a worker has not picked up yet.
     *
     * Notifications that are already processing or finished are left untouched;
     * `meta.cancelled` reports how many notifications this request cancelled.
     */
    #[Authorize('cancel', 'batch')]
    #[Endpoint(title: 'Cancel a batch')]
    public function __invoke(NotificationBatch $batch): NotificationBatchResource
    {
        $cancelledCount = $batch->cancelPendingNotifications();

        return NotificationBatchResource::make($batch)->additional(['meta' => ['cancelled' => $cancelledCount]]);
    }
}
