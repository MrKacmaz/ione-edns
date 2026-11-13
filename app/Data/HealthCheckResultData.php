<?php

namespace App\Data;

use App\Enums\HealthCheckStatus;

/**
 * The outcome of one health check.
 */
final readonly class HealthCheckResultData
{
    private function __construct(
        public HealthCheckStatus $status,
        public ?string $message = null,
        public ?float $durationMilliseconds = null,
    ) {}

    public static function ok(): self
    {
        return new self(HealthCheckStatus::Ok);
    }

    public static function degraded(string $message): self
    {
        return new self(HealthCheckStatus::Degraded, $message);
    }

    public static function failing(string $message): self
    {
        return new self(HealthCheckStatus::Failing, $message);
    }

    /**
     * Copy the result with the time the check took.
     */
    public function withDuration(float $milliseconds): self
    {
        return new self($this->status, $this->message, round($milliseconds, 1));
    }

    /**
     * @return array{status: string, message: string|null, duration_ms: float|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'duration_ms' => $this->durationMilliseconds,
        ];
    }
}
