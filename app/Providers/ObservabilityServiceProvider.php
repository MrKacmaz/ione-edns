<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Wires trace propagation into the container.
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
    }
}
