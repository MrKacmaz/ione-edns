<?php

namespace Tests\Support;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Closure;

/**
 * A health check that answers with whatever the given callback returns or throws.
 */
final readonly class StubHealthCheck implements HealthCheck
{
    /**
     * @param  Closure(): HealthCheckResultData  $check
     */
    public function __construct(private string $name, private Closure $check) {}

    /**
     * Create a check that always answers with the given result.
     */
    public static function returning(string $name, HealthCheckResultData $result): self
    {
        return new self($name, fn (): HealthCheckResultData => $result);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): HealthCheckResultData
    {
        return ($this->check)();
    }
}
