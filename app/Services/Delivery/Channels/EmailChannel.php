<?php

namespace App\Services\Delivery\Channels;

use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\DeliveryChannel;

final class EmailChannel implements DeliveryChannel
{
    private const int MAX_CONTENT_LENGTH = 10_000;

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function maxContentLength(): int
    {
        return self::MAX_CONTENT_LENGTH;
    }

    public function isValidRecipient(string $recipient): bool
    {
        return filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function recipientFormat(): string
    {
        return __('notifications.recipient_formats.email');
    }
}
