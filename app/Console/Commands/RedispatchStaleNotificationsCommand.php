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
 *
 * A notification stuck in processing after its last attempt fails instead: the
 * provider may have received it, and a notification whose outcome cannot be
 * recorded (for example because the database rejects it) would otherwise be
 * sent again by every sweep. Each push uses up an attempt, so at most
 * max_attempts sends happen in all.
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
        #[Config('notifications.delivery.max_attempts')] int $maxAttempts,
    ): int {
        // Runs first, so the requeue below finds only notifications with attempts left.
        $outOfAttempts = $this->failWithoutAttemptsLeft($processingAfterSeconds, $maxAttempts);
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

        $this->info("Failed {$outOfAttempts} stuck processing without attempts left; requeued {$stuckProcessing} stuck processing, {$lostQueued} lost queued and {$staleWaiting} stale waiting notifications.");

        return self::SUCCESS;
    }

    /**
     * Fail the notifications stuck in processing that have no delivery attempt left.
     *
     * @return int The number of notifications that failed.
     */
    private function failWithoutAttemptsLeft(int $afterSeconds, int $maxAttempts): int
    {
        $failed = 0;

        Notification::query()
            ->where('status', NotificationStatus::Processing)
            ->where('updated_at', '<', now()->subSeconds($afterSeconds))
            ->where('attempts', '>=', $maxAttempts)
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use (&$failed): void {
                foreach ($notifications as $notification) {
                    $failed += (int) Notification::markFailedIfUndelivered($notification->id, __('notifications.errors.outcome_unknown'));
                }
            });

        return $failed;
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
