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
     */
    public function reserve(NotificationChannel $channel): RateLimitReservationData;
}
