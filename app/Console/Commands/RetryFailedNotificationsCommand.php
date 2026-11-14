<?php

namespace App\Console\Commands;

use App\Actions\DispatchNotificationsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Gives every notification that failed since a moment a fresh set of attempts.
 *
 * For operators after a provider incident, for example an expired webhook.site
 * URL: the notifications that failed before the circuit opened, or while traffic
 * was too low to open it, are sent again once the provider works. Each keeps its
 * delivery history, as with the retry endpoint.
 */
class RetryFailedNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:retry-failed
        {since : Retry the notifications that failed at or after this time, for example "2 hours ago" or "2026-11-14 09:00"}
        {--channel=* : Only notifications of this channel (sms, email or push); repeat for several}
        {--dry-run : Only count the notifications that would be retried}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry the notifications that failed since the given time, for example after a provider outage';

    private const int CHUNK_SIZE = 500;

    /**
     * Execute the console command.
     */
    public function handle(DispatchNotificationsAction $dispatchNotifications): int
    {
        $since = $this->since();
        $channels = $this->channels();

        if ($since === null || $channels === null) {
            return self::FAILURE;
        }

        $failed = Notification::query()
            ->where('status', NotificationStatus::Failed)
            ->where('failed_at', '>=', (new Notification)->fromDateTime($since))
            ->when($channels !== [], fn (Builder $query) => $query->whereIn('channel', $channels));

        if ($this->option('dry-run')) {
            $this->info("{$failed->count()} failed notifications would be retried.");

            return self::SUCCESS;
        }

        $retried = 0;

        $failed->chunkById(self::CHUNK_SIZE, function (Collection $notifications) use ($dispatchNotifications, &$retried): void {
            $reset = Notification::resetForRedeliveryMany($notifications);

            $dispatchNotifications->handle($reset);
            $retried += $reset->count();
        });

        $this->info("Retried {$retried} failed notifications.");

        return self::SUCCESS;
    }

    /**
     * Read the time from which failed notifications are retried, or report why it is unreadable.
     */
    private function since(): ?CarbonImmutable
    {
        $since = (string) $this->argument('since');

        try {
            return CarbonImmutable::parse($since);
        } catch (Throwable) {
            $this->error("\"{$since}\" is not a time. Use for example \"2 hours ago\" or \"2026-11-14 09:00\".");

            return null;
        }
    }

    /**
     * Read the channels to retry, an empty list for all, or report an unknown one.
     *
     * @return list<NotificationChannel>|null
     */
    private function channels(): ?array
    {
        $channels = [];

        foreach ((array) $this->option('channel') as $value) {
            $channel = NotificationChannel::tryFrom((string) $value);

            if ($channel === null) {
                $known = implode(', ', array_column(NotificationChannel::cases(), 'value'));
                $this->error("\"{$value}\" is not a channel. Use one of: {$known}.");

                return null;
            }

            $channels[] = $channel;
        }

        return $channels;
    }
}
