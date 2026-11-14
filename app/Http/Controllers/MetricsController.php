<?php

namespace App\Http\Controllers;

use App\Services\Observability\MetricsCollector;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;

#[Group('Monitoring', 'System-wide delivery metrics for operators.', weight: 5)]
class MetricsController extends Controller
{
    /**
     * Report queue depth, in-flight notifications, success rates and latency.
     *
     * The snapshot is cached for a few seconds. The numbers cover every user's
     * notifications, so only operators may read them.
     */
    #[Authorize('operate')]
    #[Endpoint(title: 'Get delivery metrics')]
    public function __invoke(MetricsCollector $collector): JsonResponse
    {
        return response()->json(['data' => $collector->snapshot()->toArray()]);
    }
}
