<?php

namespace App\Exceptions;

use App\Enums\NotificationChannel;
use LogicException;

/**
 * Thrown when a notification channel has no delivery channel registered for it.
 */
class UnregisteredChannelException extends LogicException
{
    /**
     * Create an exception for the channel that is missing an implementation.
     */
    public static function for(NotificationChannel $channel): self
    {
        return new self(__('notifications.unregistered_channel', ['channel' => $channel->value]));
    }
}
