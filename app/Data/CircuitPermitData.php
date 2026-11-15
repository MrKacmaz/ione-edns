<?php

namespace App\Data;

/**
 * A circuit breaker's answer to whether a channel's provider may be called now.
 */
final readonly class CircuitPermitData
{
    /**
     * @param  string|null  $probeLease  Identifies the half-open probe this permit grants, so only its holder can give it back.
     */
    private function __construct(
        public bool $granted,
        public int $waitMilliseconds,
        public ?string $probeLease = null,
    ) {}

    /**
     * The provider may be called.
     */
    public static function granted(): self
    {
        return new self(true, 0);
    }

    /**
     * The provider may be called as the single probe of a half-open circuit.
     */
    public static function probe(string $lease): self
    {
        return new self(true, 0, $lease);
    }

    /**
     * The provider may not be called; ask again after the given time.
     */
    public static function denied(int $waitMilliseconds): self
    {
        return new self(false, $waitMilliseconds);
    }

    /**
     * Determine whether the permit makes its holder the probe of a half-open circuit.
     */
    public function isProbe(): bool
    {
        return $this->probeLease !== null;
    }
}
