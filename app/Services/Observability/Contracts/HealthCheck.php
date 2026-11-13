<?php

namespace App\Services\Observability\Contracts;

use App\Data\HealthCheckResultData;

/**
 * One dependency the service needs to be healthy.
 */
interface HealthCheck
{
    /**
     * Get the name the check is reported under.
     */
    public function name(): string;

    /**
     * Check the dependency. Throwing counts as a failed check.
     */
    public function check(): HealthCheckResultData;
}
