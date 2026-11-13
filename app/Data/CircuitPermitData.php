<?php

namespace App\Data;

/**
 * A circuit breaker's answer to whether a channel's provider may be called now.
 */
final readonly class CircuitPermitData
{
    private function __construct(
        public bool $granted,
        public int $waitMilliseconds,
    ) {}

    /**
     * The provider may be called.
     */
    public static function granted(): self
    {
        return new self(true, 0);
    }

    /**
     * The provider may not be called; ask again after the given time.
     */
    public static function denied(int $waitMilliseconds): self
    {
        return new self(false, $waitMilliseconds);
    }
}
