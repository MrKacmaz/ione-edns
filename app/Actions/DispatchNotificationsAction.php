<?php

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Context;

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
 *
 * Each job carries its notification's id and correlation id in its log context,
 * also when the stale sweep or the scheduler pushes it rather than the request
 * that created the notification. Its log entries from before the claim, such as
 * waits for an open circuit, can then be found by either id.
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
            Context::scope(
                fn () => $this->push($notification),
                array_filter(['notification_id' => $notification->id, 'correlation_id' => $notification->correlation_id]),
            );
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

    /**
     * Push the delivery job of one notification onto its queue.
     *
     * The queue keeps whole seconds and would round a delay down, waking the job
     * before its time; rounding up never runs it early.
     */
    private function push(Notification $notification): void
    {
        SendNotificationJob::dispatch($notification->id, $notification->channel)
            ->onQueue($notification->priority->queueFor($notification->channel))
            ->delay($notification->scheduled_at?->isFuture() ? $notification->scheduled_at->ceilSecond() : null);
    }
}
