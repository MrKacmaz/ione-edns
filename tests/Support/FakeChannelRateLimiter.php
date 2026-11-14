<?php

namespace Tests\Support;

use App\Data\RateLimitReservationData;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\ChannelRateLimiter;

/**
 * A rate limiter that answers every reservation with the same, preset reservation.
 */
final class FakeChannelRateLimiter implements ChannelRateLimiter
{
    /**
     * @var list<NotificationChannel>
     */
    public array $reservedChannels = [];

    public function __construct(private readonly RateLimitReservationData $reservation) {}

    public function reserve(NotificationChannel $channel): RateLimitReservationData
    {
        $this->reservedChannels[] = $channel;

        return $this->reservation;
    }
}
