<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Without a running, unpaused Horizon master nothing is delivered, although the API keeps accepting notifications.
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
        $masters = $this->masters->all();

        if ($masters === []) {
            return HealthCheckResultData::failing(__('health.horizon_inactive'));
        }

        // `horizon:pause` keeps the master registered while its workers take no jobs.
        foreach ($masters as $master) {
            if (($master->status ?? null) !== 'paused') {
                return HealthCheckResultData::ok();
            }
        }

        return HealthCheckResultData::failing(__('health.horizon_paused'));
    }
}
