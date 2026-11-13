<?php

namespace App\Enums;

enum NotificationStatus: string
{
    /** Persisted and waiting to be handed to the queue (outbox state). */
    case Waiting = 'waiting';

    /** Held back until its scheduled delivery time. */
    case Scheduled = 'scheduled';

    /** Pushed onto a queue and waiting for a worker. */
    case Queued = 'queued';

    /** Claimed by a worker and being delivered to the provider. */
    case Processing = 'processing';

    /** Accepted by the provider. */
    case Sent = 'sent';

    /** Permanently failed after exhausting its delivery attempts. */
    case Failed = 'failed';

    /** Cancelled before a worker picked it up. */
    case Cancelled = 'cancelled';

    /**
     * Get the statuses from which a notification may still be cancelled.
     *
     * @return list<self>
     */
    public static function cancellable(): array
    {
        return [self::Waiting, self::Scheduled, self::Queued];
    }

    /**
     * Get the statuses from which a worker may claim a notification for delivery.
     *
     * Waiting is included so that a job pushed before its notification was marked
     * as queued is not lost.
     *
     * @return list<self>
     */
    public static function claimable(): array
    {
        return [self::Waiting, self::Queued];
    }

    /**
     * Get the statuses a notification can no longer leave.
     *
     * @return list<self>
     */
    public static function final(): array
    {
        return [self::Sent, self::Failed, self::Cancelled];
    }

    /**
     * Determine whether a notification in this status may still be cancelled.
     */
    public function isCancellable(): bool
    {
        return in_array($this, self::cancellable(), true);
    }
}
