<?php

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;

/**
 * Hands notifications to the queue of their channel and priority.
 *
 * Jobs are pushed before the notifications are marked as queued: if the push
 * fails, the notifications stay waiting or scheduled and the sweeper or the
 * dispatch-due command pushes them again. A job that runs before the status
 * update can still claim its notification, because waiting notifications and
 * scheduled notifications whose time has come are claimable.
 *
 * A notification scheduled for the future is delayed until its delivery time.
 */
class DispatchNotificationsAction
{
    /**
     * The statuses a notification leaves when it is put on a queue.
     */
    private const array DISPATCHABLE_STATUSES = [NotificationStatus::Waiting, NotificationStatus::Scheduled];

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
                ->onQueue($notification->priority->queueFor($notification->channel))
                ->delay($notification->scheduled_at?->isFuture() ? $notification->scheduled_at : null);
        }

        // Formatted with the model's date format, as the query builder alone would drop the microseconds.
        $queuedAt = (new Notification)->freshTimestampString();

        $queuedRows = Notification::query()
            ->whereKey($notifications->modelKeys())
            ->whereIn('status', self::DISPATCHABLE_STATUSES)
            ->update(['status' => NotificationStatus::Queued, 'queued_at' => $queuedAt]);

        $dispatched = $notifications->filter(
            fn (Notification $notification): bool => in_array($notification->status, self::DISPATCHABLE_STATUSES, true),
        );

        // When fewer rows changed than expected, a notification was cancelled in the
        // meantime, so the in-memory models are read again instead of assumed queued.
        $allQueued = $queuedRows === $dispatched->count();

        foreach ($dispatched as $notification) {
            if ($allQueued) {
                $notification->forceFill(['status' => NotificationStatus::Queued, 'queued_at' => $queuedAt])->syncOriginal();
            } else {
                $notification->refresh();
            }
        }
    }
}
