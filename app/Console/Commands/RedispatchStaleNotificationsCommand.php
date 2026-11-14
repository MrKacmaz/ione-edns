<?php

namespace App\Console\Commands;

use App\Actions\DispatchNotificationsAction;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Collection;

/**
 * Recovers notifications that lost their delivery job.
 *
 * - Waiting too long: the job was never pushed (for example Redis was down).
 * - Processing too long: the worker died while delivering.
 * - Queued for longer than a job lives: the job was lost (for example Redis
 *   restarted without persistence), since a live job would have run or failed.
 *
 * All are pushed again. A duplicate job is harmless because a notification can
 * only be claimed once; the provider receives the notification id as its
 * idempotency key in case a dead worker had already sent it.
 */
class RedispatchStaleNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:redispatch-stale';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push notifications stuck in waiting, processing or queued back onto their queues';

    private const int CHUNK_SIZE = 500;

    /**
     * Execute the console command.
     */
    public function handle(
        DispatchNotificationsAction $dispatchNotifications,
        #[Config('notifications.delivery.stale_after_seconds.waiting')] int $waitingAfterSeconds,
        #[Config('notifications.delivery.stale_after_seconds.processing')] int $processingAfterSeconds,
        #[Config('notifications.delivery.stale_after_seconds.queued')] int $queuedAfterSeconds,
    ): int {
        $stuckProcessing = $this->requeue($dispatchNotifications, NotificationStatus::Processing, $processingAfterSeconds);
        $lostQueued = $this->requeue($dispatchNotifications, NotificationStatus::Queued, $queuedAfterSeconds);

        $staleWaiting = 0;

        Notification::query()
            ->where('status', NotificationStatus::Waiting)
            ->where('created_at', '<', now()->subSeconds($waitingAfterSeconds))
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, &$staleWaiting): void {
                $dispatchNotifications->handle($notifications);
                $staleWaiting += $notifications->count();
            });

        $this->info("Requeued {$stuckProcessing} stuck processing, {$lostQueued} lost queued and {$staleWaiting} stale waiting notifications.");

        return self::SUCCESS;
    }

    /**
     * Push the notifications left in the given status for too long onto their queues again.
     *
     * They go back to waiting first, not straight to queued: the dispatch pushes the
     * job before it marks the notification queued, so a failed push leaves them
     * waiting for the next sweep.
     *
     * @return int The number of notifications pushed again.
     */
    private function requeue(DispatchNotificationsAction $dispatchNotifications, NotificationStatus $status, int $afterSeconds): int
    {
        $requeued = 0;

        Notification::query()
            ->where('status', $status)
            ->where('updated_at', '<', now()->subSeconds($afterSeconds))
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, $status, &$requeued): void {
                Notification::query()
                    ->whereKey($notifications->modelKeys())
                    ->where('status', $status)
                    ->update(['status' => NotificationStatus::Waiting]);

                $dispatchNotifications->handle($notifications->each(
                    fn (Notification $notification) => $notification->forceFill(['status' => NotificationStatus::Waiting])->syncOriginal(),
                ));
                $requeued += $notifications->count();
            });

        return $requeued;
    }
}
