<?php

namespace App\Http\Controllers;

use App\Services\Observability\MetricsCollector;
use App\Services\Observability\PrometheusFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Validation\Rule;

class MetricsController extends Controller
{
    /**
     * Report queue depth, in-flight notifications, success rates and latency.
     *
     * Responds with JSON, or with the Prometheus text exposition format when
     * `format=prometheus`. The snapshot is cached for a few seconds. The numbers
     * cover every user's notifications, so only operators may read them.
     */
    #[Authorize('operate')]
    public function __invoke(Request $request, MetricsCollector $collector, PrometheusFormatter $formatter): JsonResponse|Response
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::in(['json', 'prometheus'])],
        ]);

        $snapshot = $collector->snapshot();

        if (($validated['format'] ?? 'json') === 'prometheus') {
            return response($formatter->format($snapshot), Response::HTTP_OK, [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            ]);
        }

        return response()->json(['data' => $snapshot->toArray()]);
    }
}
