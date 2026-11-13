<?php

namespace App\Services\Delivery\Contracts;

use App\Enums\NotificationChannel;

/**
 * The rules and behavior of one channel a notification can be delivered through.
 */
interface DeliveryChannel
{
    /**
     * Get the channel this implementation handles.
     */
    public function channel(): NotificationChannel;

    /**
     * Get the maximum number of characters the channel accepts as content.
     */
    public function maxContentLength(): int;

    /**
     * Determine whether the recipient is addressable on the channel.
     */
    public function isValidRecipient(string $recipient): bool;

    /**
     * Get a human-readable description of the recipient format the channel expects.
     */
    public function recipientFormat(): string;
}
