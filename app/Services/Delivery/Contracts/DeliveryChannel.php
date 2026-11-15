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
     * Get the length of the content, in the units the channel's limit counts.
     */
    public function contentLength(string $content): int;

    /**
     * Get the most the channel accepts for this content, in the units of contentLength().
     *
     * The limit may depend on the content, as for SMS, whose encoding decides how
     * much fits into one message.
     */
    public function maxContentLength(string $content): int;

    /**
     * Determine whether the recipient is addressable on the channel.
     */
    public function isValidRecipient(string $recipient): bool;

    /**
     * Get a human-readable description of the recipient format the channel expects.
     */
    public function recipientFormat(): string;
}
