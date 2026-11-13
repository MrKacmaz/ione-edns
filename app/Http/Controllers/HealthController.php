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
     * a token. Responds with 200 when every check passes and 503 otherwise.
     */
    public function __invoke(HealthChecker $healthChecker): JsonResponse
    {
        $results = $healthChecker->run();
        $healthy = HealthChecker::allPassed($results);

        return response()->json([
            'status' => $healthy ? HealthCheckStatus::Ok->value : HealthCheckStatus::Failing->value,
            'checks' => array_map(fn (HealthCheckResultData $result): array => $result->toArray(), $results),
            'checked_at' => now()->toIso8601ZuluString('microsecond'),
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
