<?php

namespace App\Console\Commands;

use App\Actions\DispatchNotificationsAction;
use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Puts scheduled notifications on their queues shortly before their delivery time.
 *
 * Runs every minute and picks up every scheduled notification that comes due
 * before the next run; each job is delayed until its exact delivery time.
 * Notifications that are already overdue (for example after scheduler
 * downtime) are queued without a delay.
 */
class DispatchDueNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:dispatch-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push scheduled notifications that come due within the next minute onto their queues';

    private const int CHUNK_SIZE = 500;

    /**
     * Execute the console command.
     */
    public function handle(DispatchNotificationsAction $dispatchNotifications): int
    {
        $dispatched = 0;

        Notification::query()
            ->dueForDispatch()
            ->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, &$dispatched): void {
                $dispatchNotifications->handle($notifications);
                $dispatched += $notifications->count();
            });

        $this->info("Dispatched {$dispatched} due scheduled notifications.");

        return self::SUCCESS;
    }
}
