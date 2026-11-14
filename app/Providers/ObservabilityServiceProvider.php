<?php

namespace App\Providers;

use App\Services\Observability\HealthChecker;
use App\Services\Observability\HealthChecks\DatabaseHealthCheck;
use App\Services\Observability\HealthChecks\DeliveryCircuitsHealthCheck;
use App\Services\Observability\HealthChecks\GatewayHealthCheck;
use App\Services\Observability\HealthChecks\HorizonHealthCheck;
use App\Services\Observability\HealthChecks\RedisHealthCheck;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Wires the health checks, metrics and trace propagation into the container.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register the observability services.
     *
     * A new health check needs a HealthCheck implementation and an entry here.
     */
    public function register(): void
    {
        $this->app->tag([
            DatabaseHealthCheck::class,
            RedisHealthCheck::class,
            HorizonHealthCheck::class,
            GatewayHealthCheck::class,
            DeliveryCircuitsHealthCheck::class,
        ], HealthChecker::TAG);

        // The propagators named in OTEL_PROPAGATORS, or one that adds nothing while tracing is off.
        $this->app->bind(TextMapPropagatorInterface::class, fn (): TextMapPropagatorInterface => Globals::propagator());
    }
}
