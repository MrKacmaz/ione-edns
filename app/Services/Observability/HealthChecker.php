<?php

namespace App\Services\Observability;

use App\Data\HealthCheckResultData;
use App\Enums\HealthCheckStatus;
use App\Services\Observability\Contracts\HealthCheck;
use Illuminate\Container\Attributes\Tag;
use Throwable;

/**
 * Runs every registered health check and times it.
 *
 * A check that throws is reported as failing with a generic message; the
 * exception itself is only logged, because the health endpoint is public.
 */
final readonly class HealthChecker
{
    /**
     * @param  iterable<HealthCheck>  $checks
     */
    public function __construct(#[Tag(self::TAG)] private iterable $checks) {}

    public const string TAG = 'health-checks';

    /**
     * @return array<string, HealthCheckResultData>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            $startedAt = hrtime(true);

            try {
                $result = $check->check();
            } catch (Throwable $exception) {
                report($exception);

                $result = HealthCheckResultData::failing(__('health.check_failed', ['check' => $check->name()]));
            }

            $results[$check->name()] = $result->withDuration((hrtime(true) - $startedAt) / 1_000_000);
        }

        return $results;
    }

    /**
     * Get the status of the service as a whole: failing if any check fails,
     * degraded if any check is degraded, and ok otherwise.
     *
     * @param  array<string, HealthCheckResultData>  $results
     */
    public static function overallStatus(array $results): HealthCheckStatus
    {
        $statuses = array_map(fn (HealthCheckResultData $result): HealthCheckStatus => $result->status, $results);

        return match (true) {
            in_array(HealthCheckStatus::Failing, $statuses, true) => HealthCheckStatus::Failing,
            in_array(HealthCheckStatus::Degraded, $statuses, true) => HealthCheckStatus::Degraded,
            default => HealthCheckStatus::Ok,
        };
    }
}
