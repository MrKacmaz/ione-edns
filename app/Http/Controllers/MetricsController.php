<?php

namespace App\Http\Controllers;

use App\Data\MetricsSnapshotData;
use App\Services\Observability\MetricsCollector;
use App\Services\Observability\PrometheusFormatter;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MetricsController extends Controller
{
    private const string CACHE_KEY = 'notifications:metrics:snapshot';

    /**
     * Report queue depth, in-flight notifications, success rates and latency.
     *
     * Responds with JSON, or with the Prometheus text exposition format when
     * `format=prometheus`. The snapshot is cached for a few seconds.
     */
    public function __invoke(
        Request $request,
        MetricsCollector $collector,
        PrometheusFormatter $formatter,
        CacheRepository $cache,
        #[Config('notifications.metrics.cache_seconds')] int $cacheSeconds,
    ): JsonResponse|Response {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::in(['json', 'prometheus'])],
        ]);

        $snapshot = $cacheSeconds > 0
            ? $cache->remember(self::CACHE_KEY, $cacheSeconds, fn (): MetricsSnapshotData => $collector->collect())
            : $collector->collect();

        if (($validated['format'] ?? 'json') === 'prometheus') {
            return response($formatter->format($snapshot), Response::HTTP_OK, [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            ]);
        }

        return response()->json(['data' => $snapshot->toArray()]);
    }
}
