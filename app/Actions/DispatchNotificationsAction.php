<?php

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Hands notifications to the queue of their priority.
 *
 * Jobs are pushed before the notifications are marked as queued: if the push
 * fails, the notifications stay waiting and the stale-notification sweeper
 * pushes them again. A job that runs before the status update can still claim
 * its notification, because waiting notifications are claimable.
 */
class DispatchNotificationsAction
{
    /**
     * @param  Collection<int, Notification>  $notifications
     */
    public function handle(Collection $notifications): void
    {
        if ($notifications->isEmpty()) {
            return;
        }

        foreach ($notifications as $notification) {
            SendNotificationJob::dispatch($notification->id, $notification->channel)
                ->onQueue($notification->priority->queue());
        }

        $queuedAt = now();

        Notification::query()
            ->whereKey($notifications->modelKeys())
            ->where('status', NotificationStatus::Waiting)
            ->update(['status' => NotificationStatus::Queued, 'queued_at' => $queuedAt]);

        foreach ($notifications as $notification) {
            if ($notification->status === NotificationStatus::Waiting) {
                $notification->forceFill(['status' => NotificationStatus::Queued, 'queued_at' => $queuedAt])->syncOriginal();
            }
        }
    }
}
