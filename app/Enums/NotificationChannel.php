<?php

namespace App\Enums;

use App\Services\Delivery\ChannelRegistry;

/**
 * Identifies a delivery channel in storage, validation and the API.
 *
 * Each channel's rules live in its delivery channel implementation,
 * resolved through {@see ChannelRegistry}.
 */
enum NotificationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Push = 'push';
}
