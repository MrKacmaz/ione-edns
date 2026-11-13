<?php

namespace App\Data;

use App\Enums\GatewayOutcome;

/**
 * The provider's answer to one delivery attempt.
 */
final readonly class GatewayResponseData
{
    public function __construct(
        public GatewayOutcome $outcome,
        public ?int $httpStatus = null,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * The provider accepted the notification.
     */
    public static function accepted(int $httpStatus, ?string $providerMessageId): self
    {
        return new self(GatewayOutcome::Accepted, $httpStatus, $providerMessageId);
    }

    /**
     * The attempt failed, but a later attempt may succeed.
     */
    public static function retryable(string $error, ?int $httpStatus = null, ?int $retryAfterSeconds = null): self
    {
        return new self(GatewayOutcome::RetryableFailure, $httpStatus, error: $error, retryAfterSeconds: $retryAfterSeconds);
    }

    /**
     * The provider rejected the notification for good.
     */
    public static function permanent(string $error, int $httpStatus): self
    {
        return new self(GatewayOutcome::PermanentFailure, $httpStatus, error: $error);
    }
}
