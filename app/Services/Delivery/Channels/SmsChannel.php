<?php

namespace App\Services\Delivery\Channels;

use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\DeliveryChannel;

final class SmsChannel implements DeliveryChannel
{
    /**
     * The length of a single GSM-7 SMS segment.
     */
    private const int MAX_CONTENT_LENGTH = 160;

    /**
     * An E.164 phone number: a plus sign and up to 15 digits without a leading zero.
     */
    private const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function maxContentLength(): int
    {
        return self::MAX_CONTENT_LENGTH;
    }

    public function isValidRecipient(string $recipient): bool
    {
        return preg_match(self::E164_PATTERN, $recipient) === 1;
    }

    public function recipientFormat(): string
    {
        return __('notifications.recipient_formats.sms');
    }
}
