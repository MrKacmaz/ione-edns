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
 *
 * Both are pushed again. A duplicate job is harmless because a notification can
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
    protected $description = 'Push notifications stuck in waiting or processing back onto their queues';

    private const int CHUNK_SIZE = 500;

    /**
     * Execute the console command.
     */
    public function handle(
        DispatchNotificationsAction $dispatchNotifications,
        #[Config('notifications.delivery.stale_after_seconds.waiting')] int $waitingAfterSeconds,
        #[Config('notifications.delivery.stale_after_seconds.processing')] int $processingAfterSeconds,
    ): int {
        $requeued = 0;

        Notification::query()
            ->where('status', NotificationStatus::Processing)
            ->where('updated_at', '<', now()->subSeconds($processingAfterSeconds))
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, &$requeued): void {
                // Back to waiting, not queued: the dispatch pushes the job before it marks the
                // notification queued, so a failed push leaves it waiting for the next sweep.
                Notification::query()
                    ->whereKey($notifications->modelKeys())
                    ->where('status', NotificationStatus::Processing)
                    ->update(['status' => NotificationStatus::Waiting]);

                $dispatchNotifications->handle($notifications->each(
                    fn (Notification $notification) => $notification->forceFill(['status' => NotificationStatus::Waiting])->syncOriginal(),
                ));
                $requeued += $notifications->count();
            });

        $redispatched = 0;

        Notification::query()
            ->where('status', NotificationStatus::Waiting)
            ->where('created_at', '<', now()->subSeconds($waitingAfterSeconds))
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, &$redispatched): void {
                $dispatchNotifications->handle($notifications);
                $redispatched += $notifications->count();
            });

        $this->info("Requeued {$requeued} stuck processing and {$redispatched} stale waiting notifications.");

        return self::SUCCESS;
    }
}
