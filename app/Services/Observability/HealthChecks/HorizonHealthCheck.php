<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Without a running Horizon master nothing is delivered, although the API keeps accepting notifications.
 */
final readonly class HorizonHealthCheck implements HealthCheck
{
    public function __construct(private MasterSupervisorRepository $masters) {}

    public function name(): string
    {
        return 'horizon';
    }

    public function check(): HealthCheckResultData
    {
        return $this->masters->all() === []
            ? HealthCheckResultData::failing(__('health.horizon_inactive'))
            : HealthCheckResultData::ok();
    }
}
