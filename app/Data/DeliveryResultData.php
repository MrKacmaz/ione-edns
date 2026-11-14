<?php

namespace App\Data;

use App\Enums\DeliveryOutcome;

/**
 * What one delivery run did with a notification.
 */
final readonly class DeliveryResultData
{
    private function __construct(
        public DeliveryOutcome $outcome,
        public ?int $retryDelaySeconds = null,
    ) {}

    public static function sent(): self
    {
        return new self(DeliveryOutcome::Sent);
    }

    public static function retryScheduled(int $delaySeconds): self
    {
        return new self(DeliveryOutcome::RetryScheduled, $delaySeconds);
    }

    /**
     * The notification is not due yet; the job waits the given seconds without an attempt.
     */
    public static function deferred(int $delaySeconds): self
    {
        return new self(DeliveryOutcome::Deferred, $delaySeconds);
    }

    public static function failed(): self
    {
        return new self(DeliveryOutcome::Failed);
    }

    public static function skipped(): self
    {
        return new self(DeliveryOutcome::Skipped);
    }
}
