<?php

namespace App\Services\Delivery\Channels;

use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\DeliveryChannel;

final class PushChannel implements DeliveryChannel
{
    private const int MAX_CONTENT_LENGTH = 240;

    /**
     * The length range of a device token as issued by APNs or FCM.
     */
    private const int MIN_TOKEN_LENGTH = 16;

    private const int MAX_TOKEN_LENGTH = 512;

    /**
     * A device token made of URL-safe characters, with the length bounds filled in by sprintf().
     */
    private const string DEVICE_TOKEN_PATTERN = '/^[A-Za-z0-9:_\-.]{%d,%d}$/';

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    public function maxContentLength(): int
    {
        return self::MAX_CONTENT_LENGTH;
    }

    public function isValidRecipient(string $recipient): bool
    {
        $pattern = sprintf(self::DEVICE_TOKEN_PATTERN, self::MIN_TOKEN_LENGTH, self::MAX_TOKEN_LENGTH);

        return preg_match($pattern, $recipient) === 1;
    }

    public function recipientFormat(): string
    {
        return __('notifications.recipient_formats.push', [
            'min' => self::MIN_TOKEN_LENGTH,
            'max' => self::MAX_TOKEN_LENGTH,
        ]);
    }
}
