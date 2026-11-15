<?php

namespace App\Providers;

use App\Services\Observability\SpanFlusher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Wires tracing and trace propagation into the container.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register the observability services.
     */
    public function register(): void
    {
        // The propagators named in OTEL_PROPAGATORS, or one that adds nothing while tracing is off.
        $this->app->bind(TextMapPropagatorInterface::class, fn (): TextMapPropagatorInterface => Globals::propagator());

        // The application's own spans, such as notification.deliver; no-op spans while tracing is off.
        $this->app->bind(TracerInterface::class, fn (): TracerInterface => Globals::tracerProvider()->getTracer('ione-edns'));

        // One per process, as it remembers when it last flushed.
        $this->app->singleton(SpanFlusher::class, fn (): SpanFlusher => new SpanFlusher(Globals::tracerProvider(), Clock::getDefault()));
    }

    /**
     * Bootstrap the observability services.
     */
    public function boot(Dispatcher $events): void
    {
        // Workers export their spans between jobs (see SpanFlusher).
        $events->listen(JobAttempted::class, fn () => $this->app->make(SpanFlusher::class)->flushIfDue());
    }
}
