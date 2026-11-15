<?php

namespace App\Services\Observability;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\Contrib\Otlp\SpanExporterFactory;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;
use OpenTelemetry\SDK\Registry;
use OpenTelemetry\SDK\Trace\SpanExporter\SpanExporterFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

/**
 * The OTLP span exporter, configured by the usual OTEL_EXPORTER_OTLP_* variables,
 * trying each export once and guarded by a ResilientSpanExporter.
 *
 * Selected with OTEL_TRACES_EXPORTER=otlp_resilient (see bootstrap/tracing.php).
 */
final class ResilientOtlpSpanExporterFactory implements SpanExporterFactoryInterface
{
    /**
     * The name OTEL_TRACES_EXPORTER selects this exporter by.
     */
    public const string NAME = 'otlp_resilient';

    /**
     * Seconds spans are dropped after a failed export before the backend is tried again.
     */
    private const int COOLDOWN_SECONDS = 30;

    public function create(): SpanExporterInterface
    {
        $transportFactory = new SingleAttemptTransportFactory(Registry::transportFactory($this->protocol()));

        $exporter = (new SpanExporterFactory($transportFactory))->create();

        return new ResilientSpanExporter($exporter, Clock::getDefault(), self::COOLDOWN_SECONDS);
    }

    /**
     * Get the OTLP protocol for traces, as the OTLP exporter reads it.
     */
    private function protocol(): string
    {
        return Configuration::has(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            ? Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL)
            : Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL);
    }
}
