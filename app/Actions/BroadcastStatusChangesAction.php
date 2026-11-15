<?php

namespace App\Actions;

use App\Events\NotificationStatusChanged;
use App\Models\Notification;
use App\Services\Observability\NotificationTracer;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Announces the status of many notifications at once, such as a new batch.
 *
 * Dispatching NotificationStatusChanged once per notification would push its
 * broadcast job one by one: a batch of 1000 created and queued would cost the
 * request 2000 queue round trips. Here the broadcast jobs are pushed together, in
 * one Redis pipeline, without recording a tracing span per job (see
 * DispatchNotificationsAction).
 *
 * The payloads capture each notification's status now; the jobs are pushed once
 * the surrounding transaction has committed, as for the event itself. A failed
 * push is reported but not thrown: the announcement is a convenience, and the
 * notifications are committed and on their way regardless.
 */
class BroadcastStatusChangesAction
{
    public function __construct(
        private readonly QueueFactory $queues,
        private readonly NotificationTracer $tracer,
    ) {}

    /**
     * @param  iterable<Notification>  $notifications
     */
    public function handle(iterable $notifications): void
    {
        $events = [];

        foreach ($notifications as $notification) {
            $events[] = new NotificationStatusChanged($notification);
        }

        if ($events === []) {
            return;
        }

        $queue = $events[0]->queue;
        $jobs = array_map(fn (NotificationStatusChanged $event): BroadcastEvent => new BroadcastEvent($event), $events);

        DB::afterCommit(function () use ($jobs, $queue): void {
            try {
                $this->tracer->withoutRecordingSpans(fn () => $this->queues->connection()->bulk($jobs, '', $queue));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
