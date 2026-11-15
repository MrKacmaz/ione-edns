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

    /**
     * @param  array<string, int>  $limitsPerSecond  The limit per channel; 100 for a channel not given.
     */
    public function __construct(
        private readonly RateLimitReservationData $reservation,
        private readonly array $limitsPerSecond = [],
    ) {}

    public function reserve(NotificationChannel $channel): RateLimitReservationData
    {
        $this->reservedChannels[] = $channel;

        return $this->reservation;
    }

    public function limitPerSecond(NotificationChannel $channel): int
    {
        return $this->limitsPerSecond[$channel->value] ?? 100;
    }
}
