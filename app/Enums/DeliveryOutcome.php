<?php

namespace App\Enums;

/**
 * What a delivery run did with a notification.
 */
enum DeliveryOutcome: string
{
    /** The notification was handed to the provider. */
    case Sent = 'sent';

    /** The attempt failed and another attempt is due after a delay. */
    case RetryScheduled = 'retry_scheduled';

    /** The job came before the notification was due (its next attempt or its scheduled time) and waits for it. */
    case Deferred = 'deferred';

    /** The notification failed for good. */
    case Failed = 'failed';

    /** The notification was not deliverable any more (cancelled, already handled). */
    case Skipped = 'skipped';
}
