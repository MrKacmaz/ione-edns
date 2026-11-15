<?php

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Services\Observability\NotificationTracer;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Hands notifications to the queue of their channel and priority.
 *
 * The jobs of each queue are pushed together, in one Redis pipeline: a batch of
 * 1000 notifications takes one round trip per queue instead of 1000. The span the
 * queue instrumentation records for every pushed job is left out, as recording
 * 1000 of them cost about a second per batch; each notification's delivery has
 * its own trace (see NotificationTracer).
 *
 * Jobs are pushed before the notifications are marked as queued: if the push
 * fails, the notifications stay waiting or scheduled and the sweeper or the
 * dispatch-due command pushes them again. A job that runs before the status
 * update can still claim its notification, because waiting notifications and
 * scheduled notifications whose time has come are claimable.
 *
 * A notification scheduled for the future is delayed until its delivery time.
 *
 * Each job carries its notification's id and correlation id, also when the stale
 * sweep or the scheduler pushes it rather than the request that created the
 * notification; the worker puts both into the job's log context (see
 * AssignNotificationContext).
 */
class DispatchNotificationsAction
{
    /**
     * The statuses a notification leaves when it is put on a queue.
     */
    private const array DISPATCHABLE_STATUSES = [NotificationStatus::Waiting, NotificationStatus::Scheduled];

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly NotificationTracer $tracer,
    ) {}

    /**
     * @param  Collection<int, Notification>  $notifications
     */
    public function handle(Collection $notifications): void
    {
        if ($notifications->isEmpty()) {
            return;
        }

        $jobsByQueue = [];

        foreach ($notifications as $notification) {
            $jobsByQueue[$notification->priority->queueFor($notification->channel)][] = $this->jobFor($notification);
        }

        $this->tracer->withoutRecordingSpans(function () use ($jobsByQueue): void {
            foreach ($jobsByQueue as $queue => $jobs) {
                $this->queues->connection()->bulk($jobs, '', $queue);
            }
        });

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
     * Build the delivery job of one notification.
     *
     * The queue keeps whole seconds and would round a delay down, waking the job
     * before its time; rounding up never runs it early.
     */
    private function jobFor(Notification $notification): SendNotificationJob
    {
        return (new SendNotificationJob($notification->id, $notification->channel, $notification->correlation_id))
            ->delay($notification->scheduled_at?->isFuture() ? $notification->scheduled_at->ceilSecond() : null);
    }
}
