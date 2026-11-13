<?php

namespace App\Http\Controllers;

use App\Data\HealthCheckResultData;
use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecker;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HealthController extends Controller
{
    /**
     * Report whether the service and every dependency it needs are healthy.
     *
     * Public, so load balancers and container health checks can call it without
     * a token. Responds with 503 when a check fails, and with 200 otherwise, also
     * when the service is degraded (`warn`), for example while a channel's
     * delivery circuit is open: the API still accepts notifications then.
     */
    public function __invoke(HealthChecker $healthChecker): JsonResponse
    {
        $results = $healthChecker->run();
        $status = HealthChecker::overallStatus($results);

        return response()->json([
            'status' => $status->value,
            'checks' => array_map(fn (HealthCheckResultData $result): array => $result->toArray(), $results),
            'checked_at' => now()->toIso8601ZuluString('microsecond'),
        ], $status === HealthCheckStatus::Failing ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK);
    }
}
