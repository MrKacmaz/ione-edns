<?php

namespace App\Data;

/**
 * A rate limiter's answer to a request for a send slot.
 */
final readonly class RateLimitReservationData
{
    private function __construct(
        public bool $granted,
        public int $waitMilliseconds,
    ) {}

    /**
     * A slot is reserved; send after waiting the given time.
     */
    public static function granted(int $waitMilliseconds): self
    {
        return new self(true, $waitMilliseconds);
    }

    /**
     * No slot is free soon enough; the next one opens after the given time.
     */
    public static function denied(int $waitMilliseconds): self
    {
        return new self(false, $waitMilliseconds);
    }
}
