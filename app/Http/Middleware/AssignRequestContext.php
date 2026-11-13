<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use OpenTelemetry\API\Trace\Span;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id and, when tracing is active, its trace id.
 *
 * Both are stored in Laravel's Context, so they are written to every log entry of
 * the request and travel with every job the request dispatches. The correlation
 * id is taken from the X-Correlation-ID header when it is a UUID, generated
 * otherwise, and returned in the same response header.
 */
class AssignRequestContext
{
    public const string CORRELATION_ID_HEADER = 'X-Correlation-ID';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->correlationIdFrom($request);

        Context::add('correlation_id', $correlationId);

        $spanContext = Span::getCurrent()->getContext();

        if ($spanContext->isValid()) {
            Context::add('trace_id', $spanContext->getTraceId());
        }

        $response = $next($request);

        $response->headers->set(self::CORRELATION_ID_HEADER, $correlationId);

        return $response;
    }

    /**
     * Use the client's correlation id when it is a UUID, otherwise start a new one.
     */
    private function correlationIdFrom(Request $request): string
    {
        $correlationId = $request->header(self::CORRELATION_ID_HEADER);

        return is_string($correlationId) && Str::isUuid($correlationId)
            ? Str::lower($correlationId)
            : (string) Str::uuid7();
    }
}
