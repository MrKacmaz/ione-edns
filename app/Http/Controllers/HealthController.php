<?php

namespace App\Http\Controllers;

use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecker;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
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
     *
     * A browser, which prefers HTML, gets a status page with the same checks and
     * status code. Every other client, and a request with `?format=json`, gets JSON.
     */
    public function __invoke(Request $request, HealthChecker $healthChecker): JsonResponse|HttpResponse
    {
        $results = $healthChecker->run();
        $status = HealthChecker::overallStatus($results);
        $statusCode = $status === HealthCheckStatus::Failing ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK;
        $checkedAt = now();

        if ($this->wantsStatusPage($request)) {
            return response($this->statusPage($results, $status, $checkedAt->toIso8601ZuluString()), $statusCode);
        }

        return response()->json([
            'status' => $status->value,
            'checks' => array_map(fn (array $result): array => [
                'status' => $result['status']->value,
                'message' => $result['message'],
            ], $results),
            'checked_at' => $checkedAt->toIso8601ZuluString('microsecond'),
        ], $statusCode);
    }

    /**
     * Whether the client is a browser that asks for HTML rather than JSON.
     *
     * A client that accepts anything (`*` / `*`) or sends no Accept header gets JSON.
     */
    private function wantsStatusPage(Request $request): bool
    {
        if ($request->query('format') === 'json') {
            return false;
        }

        return $request->prefers(['application/json', 'text/html']) === 'text/html';
    }

    /**
     * @param  array<string, array{status: HealthCheckStatus, message: ?string}>  $results
     */
    private function statusPage(array $results, HealthCheckStatus $status, string $checkedAt): View
    {
        return view('health', [
            'status' => $status,
            'checks' => $results,
            'checkedAt' => $checkedAt,
            'links' => $this->links(),
        ]);
    }

    /**
     * Tools an operator opens next to the status page, keyed by their label in
     * `health.page.links`. A tool that is not set up is left out.
     *
     * @return array<string, string>
     */
    private function links(): array
    {
        $links = [
            'dashboard' => route('dashboard'),
            'horizon' => url()->to(config()->string('horizon.path', 'horizon')),
        ];

        $jaegerUrl = config('services.jaeger.ui_url');

        if (is_string($jaegerUrl) && $jaegerUrl !== '') {
            $links['jaeger'] = $jaegerUrl;
        }

        if (Route::has('scramble.docs.ui')) {
            $links['api_docs'] = route('scramble.docs.ui');
        }

        $links['json'] = route('health', ['format' => 'json']);

        return $links;
    }
}
