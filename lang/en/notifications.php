<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Exception Messages
    |--------------------------------------------------------------------------
    |
    | Messages carried by exceptions thrown while notifications are created,
    | resolved or cancelled.
    |
    */

    'cannot_be_cancelled' => 'The notification can no longer be cancelled because it is :status.',

    'unregistered_channel' => 'No delivery channel is registered for the [:channel] channel.',

    'not_created_nor_found' => 'Notification at position :position was neither created nor found.',

    'cannot_be_retried' => 'Only failed notifications can be retried; this notification is :status.',

    'template_variable_missing' => 'The template variable [:variable] has no text or number value.',

    /*
    |--------------------------------------------------------------------------
    | Delivery Errors
    |--------------------------------------------------------------------------
    |
    | Reasons a delivery attempt failed, stored as the notification's last error
    | and in its delivery history.
    |
    */

    'errors' => [
        'http_status' => 'The provider responded with HTTP :status.',
        'unreachable' => 'The provider could not be reached: :reason',
        'unexpected' => 'The delivery attempt failed unexpectedly: :reason',
        'job_failed' => 'The delivery job failed: :reason',
        'gateway_url_missing' => 'The notification gateway URL is not configured.',
        'rate_limiter_reply' => 'The rate limiter returned an unexpected reply.',
        'rate_limiter_client' => 'The rate limiter requires the phpredis Redis client.',
        'circuit_breaker_reply' => 'The circuit breaker returned an unexpected reply.',
        'circuit_breaker_client' => 'The circuit breaker requires the phpredis Redis client.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Recipient Formats
    |--------------------------------------------------------------------------
    |
    | The recipient each delivery channel expects, keyed by channel. They are
    | embedded in validation messages ("The recipient must be ...").
    |
    */

    'recipient_formats' => [
        'sms' => 'an E.164 phone number (e.g. +905551234567)',
        'email' => 'a valid email address',
        'push' => 'a device token of :min to :max characters',
    ],

];
