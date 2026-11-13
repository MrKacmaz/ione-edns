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
