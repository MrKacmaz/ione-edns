<?php

namespace App\Enums;

/**
 * How the provider answered a single delivery attempt.
 */
enum GatewayOutcome: string
{
    /** The provider accepted the notification. */
    case Accepted = 'accepted';

    /** The attempt failed for a reason that may pass on a later attempt (timeout, 5xx). */
    case RetryableFailure = 'retryable_failure';

    /** The provider asked to slow down (429); it is up and the attempt is retried later. */
    case Throttled = 'throttled';

    /** The provider rejected the notification; trying again cannot succeed (other 4xx). */
    case PermanentFailure = 'permanent_failure';
}
