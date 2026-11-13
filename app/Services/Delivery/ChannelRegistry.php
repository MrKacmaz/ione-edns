<?php

namespace App\Services\Delivery;

use App\Enums\NotificationChannel;
use App\Exceptions\UnregisteredChannelException;
use App\Services\Delivery\Contracts\DeliveryChannel;

/**
 * Resolves the delivery channel implementation for a notification channel.
 */
final class ChannelRegistry
{
    /**
     * The registered delivery channels, keyed by notification channel value.
     *
     * @var array<value-of<NotificationChannel>, DeliveryChannel>
     */
    private array $deliveryChannels = [];

    /**
     * Register a delivery channel under the notification channel it handles.
     */
    public function register(DeliveryChannel $deliveryChannel): self
    {
        $this->deliveryChannels[$deliveryChannel->channel()->value] = $deliveryChannel;

        return $this;
    }

    /**
     * Get the delivery channel that handles the notification channel.
     *
     * @throws UnregisteredChannelException
     */
    public function for(NotificationChannel $channel): DeliveryChannel
    {
        return $this->deliveryChannels[$channel->value] ?? throw UnregisteredChannelException::for($channel);
    }
}
