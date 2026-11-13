<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a notification is about to be sent but the provider is not configured.
 */
class GatewayNotConfiguredException extends RuntimeException
{
    /**
     * Create an exception for a gateway without a URL.
     */
    public static function missingUrl(): self
    {
        return new self(__('notifications.errors.gateway_url_missing'));
    }
}
