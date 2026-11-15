<?php

namespace App\Services\Delivery\Contracts;

use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;

/**
 * Spaces out sends per channel so that no channel exceeds its rate limit.
 */
interface ChannelRateLimiter
{
    /**
     * Reserve the next send slot of the channel.
     *
     * A paused channel grants no slot; the reservation says when to look again.
     */
    public function reserve(NotificationChannel $channel): RateLimitReservationData;

    /**
     * Get the messages per second the channel may send; 0 while it is paused.
     */
    public function limitPerSecond(NotificationChannel $channel): int;
}
