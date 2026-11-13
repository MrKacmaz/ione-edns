<?php

namespace App\Services\Delivery\Contracts;

use App\Data\GatewayResponseData;
use App\Models\Notification;

/**
 * The external provider that delivers notifications to recipients.
 */
interface NotificationGateway
{
    /**
     * Hand one notification to the provider and classify its answer.
     *
     * Implementations report provider and network failures through the result
     * instead of throwing, so the caller can decide whether to try again.
     */
    public function send(Notification $notification): GatewayResponseData;
}
